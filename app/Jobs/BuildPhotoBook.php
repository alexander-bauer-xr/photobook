<?php

namespace App\Jobs;

use App\Services\{NextcloudPhotoRepository, ImageProbe, LayoutPlanner, PhotoBookBuilder, PageGrouper, LayoutPlannerV2, LayoutTemplates, FeatureRepository};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;

class BuildPhotoBook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Allow long-running builds (in seconds) */
    public int $timeout = 1200; // 20 minutes

    public function __construct(public array $options = [])
    {
    }

    public function handle(
        NextcloudPhotoRepository $repo,
        ImageProbe $probe,
        LayoutPlanner $planner,
        PageGrouper $grouper,
        LayoutPlannerV2 $plannerV2,
        PhotoBookBuilder $builder
    ): void {
        // Prevent script timeouts in some environments
        if (function_exists('set_time_limit'))
            @set_time_limit(0);

        $jobStart = microtime(true);
        $memStart = memory_get_usage(true);
        logger()->info('PB: job start', [
            'opts' => $this->options,
            'mem_mb' => round($memStart / 1048576, 1),
        ]);

        $folder = $this->options['folder'] ?? Config::get('photobook.folder');
        
        // Helper to update progress status
        $updateProgress = function(int $progress, string $step, string $state = 'running') use ($folder) {
            try {
                $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
                if (!is_dir($cacheRoot)) {
                    @mkdir($cacheRoot, 0755, true);
                }
                @file_put_contents($cacheRoot . DIRECTORY_SEPARATOR . 'task.status.json', json_encode([
                    'state' => $state,
                    'progress' => $progress,
                    'step' => $step,
                    'updatedAt' => date(DATE_ATOM)
                ]));
                // Also log to console for visibility
                logger()->info("PB: [{$progress}%] {$step}");
            } catch (\Throwable $e) {}
        };
        
        $updateProgress(1, 'Starting build...');
        $paper = $this->options['paper'] ?? Config::get('photobook.paper');
        $orientation = $this->options['orientation'] ?? Config::get('photobook.orientation', 'landscape');
        $dpi = (int) ($this->options['dpi'] ?? Config::get('photobook.dpi'));

        $hash = sha1((string) $folder);
        $cacheRoot = storage_path('app/pdf-exports/_cache/' . $hash);
        $pagesPath = $cacheRoot . DIRECTORY_SEPARATOR . 'pages.json';
        $coverMeta = [];
        try {
            if (is_file($pagesPath)) {
                $doc = json_decode((string) @file_get_contents($pagesPath), true) ?: [];
                if (!empty($doc['cover']) && is_array($doc['cover'])) {
                    $coverMeta = $doc['cover'];
                }
            }
        } catch (\Throwable $e) {
            logger()->debug('PB: cover metadata read failed', ['err' => $e->getMessage()]);
        }
        $this->options = $this->normalizeCoverOptions($this->options, $coverMeta);
        $coverSourcePath = isset($this->options['cover_source_path']) && is_string($this->options['cover_source_path'])
            ? $this->options['cover_source_path']
            : null;

        $updateProgress(5, 'Listing photos from Nextcloud...');
        $t = microtime(true);
        $photos = $repo->listPhotos($folder);
        logger()->info('PB: repo listed photos', [
            'count' => count($photos),
            'secs' => round(microtime(true) - $t, 2),
            'mem_mb' => round(memory_get_usage(true) / 1048576, 1),
        ]);
        $updateProgress(10, 'Found ' . count($photos) . ' photos');

        if ($coverSourcePath) {
            $before = count($photos);
            $photos = array_values(array_filter($photos, function ($p) use ($coverSourcePath) {
                $path = null;
                if (is_object($p) && isset($p->path)) {
                    $path = $p->path;
                } elseif (is_array($p) && isset($p['path'])) {
                    $path = $p['path'];
                }
                return (string) ($path ?? '') !== (string) $coverSourcePath;
            }));
            $removed = $before - count($photos);
            if ($removed > 0) {
                logger()->info('PB: excluded cover photo from interior pages', ['removed' => $removed, 'path' => $coverSourcePath]);
            }
        }

        // Run feature extraction if ML is enabled and we're missing features
        if (config('photobook.ml.enable') && \Illuminate\Support\Facades\Schema::hasTable('photo_features')) {
            $needsExtraction = false;
            
            // Check if we have any ML features that require sidecar processing
            $needsSidecar = config('photobook.ml.faces') || 
                           config('photobook.ml.aesthetic') || 
                           config('photobook.ml.saliency') || 
                           config('photobook.ml.horizon');
            
            if ($needsSidecar && count($photos) > 0) {
                // Sample a few photos to see if we have features
                $samplePaths = array_slice(array_map(fn($p) => $p->path, $photos), 0, 5);
                $existing = \App\Models\PhotoFeature::whereIn('path', $samplePaths)->count();
                
                if ($existing < count($samplePaths) * 0.5) { // Less than 50% have features
                    $needsExtraction = true;
                }
            }
            
            if ($needsExtraction) {
                try {
                    $updateProgress(12, 'Extracting ML features (this may take a while)...');
                    
                    logger()->info('PB: Running feature extraction for folder: ' . $folder);
                    \Illuminate\Support\Facades\Artisan::call('photobook:extract', [
                        'folder' => $folder,
                        '--force' => false
                    ]);
                    
                    logger()->info('PB: Feature extraction completed');
                } catch (\Throwable $e) {
                    logger()->error('PB: Feature extraction failed: ' . $e->getMessage());
                    // Continue with build even if feature extraction fails
                }
            }
        }

        // Optional: limit for debugging large sets
        if (!empty($this->options['max_photos']) && is_numeric($this->options['max_photos'])) {
            $photos = array_slice($photos, 0, (int) $this->options['max_photos']);
            logger()->info('PB: limiting photos for debug', ['max' => (int) $this->options['max_photos']]);
        }

        $updateProgress(20, 'Probing image dimensions...');
        $t = microtime(true);
        $photos = $probe->fillDimensions($photos);
        $withDims = 0;
        foreach ($photos as $p) {
            if ($p->width && $p->height)
                $withDims++;
        }
        logger()->info('PB: probe filled dimensions', [
            'count' => count($photos),
            'with_dims' => $withDims,
            'secs' => round(microtime(true) - $t, 2),
            'mem_mb' => round(memory_get_usage(true) / 1048576, 1),
        ]);
        $updateProgress(30, 'Probed ' . $withDims . '/' . count($photos) . ' images');
        usort($photos, function($a,$b) {
            $ta = $a->takenAt?->getTimestamp() ?? PHP_INT_MIN;
            $tb = $b->takenAt?->getTimestamp() ?? PHP_INT_MIN;
            return $ta <=> $tb ?: strcmp($a->filename, $b->filename);
        });

        // Optional dedupe burst by pHash within small time windows
    if (config('photobook.ml.enable') && config('photobook.ml.phash') && \Illuminate\Support\Facades\Schema::hasTable('photo_features')) {
            $updateProgress(35, 'Removing duplicate photos...');
            $featRepo = app(FeatureRepository::class);
            $paths = array_map(fn($p)=>$p->path, $photos);
            $features = $featRepo->getMany($paths);
            $filtered = [];
            $window = 30; // seconds
            for ($i=0; $i<count($photos); $i++) {
                $keep = true;
                $pi = $photos[$i];
                $ph_i = $features[$pi->path]->phash ?? null;
                for ($j=max(0,$i-5); $j<$i; $j++) {
                    $pj = $photos[$j];
                    $dt = abs(($pi->takenAt?->getTimestamp() ?? 0) - ($pj->takenAt?->getTimestamp() ?? 0));
                    if ($dt > $window) continue;
                    $ph_j = $features[$pj->path]->phash ?? null;
                    $ham = FeatureRepository::hamming($ph_i, $ph_j);
                    if ($ham !== null && $ham <= 5) {
                        // prefer sharper
                        $sh_i = (float)($features[$pi->path]->sharpness ?? 0);
                        $sh_j = (float)($features[$pj->path]->sharpness ?? 0);
                        if ($sh_i <= $sh_j) { $keep = false; break; } else { unset($filtered[$j]); }
                    }
                }
                if ($keep) $filtered[$i] = $pi;
            }
            $photosBefore = count($photos);
            $photos = array_values($filtered);
            logger()->info('PB: dedupe by pHash', ['before'=>$photosBefore,'after'=>count($photos)]);
        }

        $useV2 = (bool) ($this->options['v2'] ?? true);
        if ($useV2) {
            $updateProgress(40, 'Grouping photos into pages...');
            $t = microtime(true);
            $groups = $grouper->group($photos, 4);
            $pages = [];
            $recentTpls = [];
            // Load review overrides if present (latest entry for each page wins)
            $overridesByPage = [];
            $jsonOverridesByPage = [];
            try {
                $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
                $ovFile = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.log';
                if (is_file($ovFile)) {
                    $lines = @file($ovFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                    foreach ($lines as $ln) {
                        $j = json_decode($ln, true);
                        if (!is_array($j)) continue;
                        if (!empty($j['folder']) && (string)$j['folder'] !== (string)$folder) continue;
                        $pg = (int) ($j['page'] ?? 0);
                        $tid = (string) ($j['templateId'] ?? '');
                        if ($pg >= 1 && $tid !== '') {
                            $overridesByPage[$pg] = $tid; // latest wins by file order
                        }
                    }
                }
                // Also read structured overrides.json to honor explicit templateId per page
                $ovJson = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.json';
                if (is_file($ovJson)) {
                    $ov = json_decode((string) @file_get_contents($ovJson), true) ?: [];
                    $pagesOv = (array) ($ov['pages'] ?? []);
                    foreach ($pagesOv as $k => $entry) {
                        $n = (int) $k;
                        $tid = (string) ($entry['templateId'] ?? '');
                        if ($n >= 1 && $tid !== '') {
                            $jsonOverridesByPage[$n] = $tid;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
            foreach ($groups as $group) {
                $pageIndex = count($pages) + 1; // 1-based page index in review
                $overrideTpl = $jsonOverridesByPage[$pageIndex] ?? ($overridesByPage[$pageIndex] ?? null);
                if ($overrideTpl) {
                    $choice = $plannerV2->chooseLayoutWithTemplate($group, $overrideTpl);
                } else {
                    $choice = $plannerV2->chooseLayout($group, ['recent' => $recentTpls]);
                }
                $pages[] = [
                    'template' => 'generic',
                    // keep the actual chosen template id for downstream exporters/debug
                    'templateId' => $choice['template'] ?? null,
                    'slots' => $choice['slots'],
                    'items' => $choice['items'],
                    'photos' => $group, // keep for asset copy
                ];
                // Track recent template ids for variety penalty
                if (!empty($choice['template'])) {
                    $recentTpls[] = $choice['template'];
                    if (count($recentTpls) > 12) { $recentTpls = array_slice($recentTpls, -12); }
                }
            }
            logger()->info('PB: planner v2 pages', [
                'pages' => count($pages),
                'secs' => round(microtime(true) - $t, 2),
                'mem_mb' => round(memory_get_usage(true) / 1048576, 1),
            ]);
            $updateProgress(50, 'Planned ' . count($pages) . ' pages');

            // ---- V2: Write pages.json for the React editor ----
            $hash = sha1($folder);
            $cacheDir = storage_path('app/pdf-exports/_cache/' . $hash);
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

            $pagesForJson = [];
            foreach ($pages as $idx => $page) {
                $items = [];
                foreach ((array)($page['items'] ?? []) as $item) {
                    $photo = $item['photo'] ?? null;
                    $photoArr = null;
                    if ($photo instanceof \App\DTO\PhotoDto) {
                        $photoArr = [
                            'path'     => $photo->path,
                            'filename' => $photo->filename,
                            'width'    => $photo->width,
                            'height'   => $photo->height,
                            'ratio'    => $photo->ratio,
                            'takenAt'  => $photo->takenAt?->format(\DateTimeInterface::ATOM),
                        ];
                    } elseif (is_array($photo)) {
                        $photoArr = $photo;
                    }
                    $relPath = $photoArr ? ltrim(str_replace($folder, '', $photoArr['path'] ?? ''), '/') : null;
                    $items[] = [
                        'slotIndex'      => (int)($item['slotIndex'] ?? 0),
                        'photo'          => $photoArr,
                        'src'            => $relPath ? '/photobook/asset/' . $hash . '/' . $relPath : null,
                        'crop'           => $item['crop'] ?? 'cover',
                        'objectPosition' => $item['objectPosition'] ?? 'center',
                    ];
                }
                $pagesForJson[] = [
                    'n'          => $idx,
                    'template'   => $page['template'] ?? 'generic',
                    'templateId' => $page['templateId'] ?? null,
                    'slots'      => $page['slots'] ?? [],
                    'items'      => $items,
                ];
            }

            $pagesDoc = [
                'folder'     => $folder,
                'created_at' => now()->toIso8601String(),
                'count'      => count($pagesForJson),
                'pages'      => $pagesForJson,
            ];
            @file_put_contents(
                $cacheDir . DIRECTORY_SEPARATOR . 'pages.json',
                json_encode($pagesDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            logger()->info('PB: pages.json written', ['path' => $cacheDir . '/pages.json', 'pages' => count($pagesForJson)]);
            $updateProgress(100, 'pages.json generated — ' . count($pagesForJson) . ' pages ready', 'finished');
            return;
        } else {
            $t = microtime(true);
            $pages = $planner->plan($photos, $this->options);
            logger()->info('PB: planner pages', [
                'pages' => count($pages),
                'secs' => round(microtime(true) - $t, 2),
                'mem_mb' => round(memory_get_usage(true) / 1048576, 1),
            ]);
        }

        // Apply editor overrides (positions, captions, etc.) from overrides.json if available
        try {
            $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
            $ovPath = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.json';
            if (is_file($ovPath)) {
                $ov = json_decode((string) @file_get_contents($ovPath), true) ?: [];
                $ovPages = (array) ($ov['pages'] ?? []);
                if (!empty($ovPages)) {
                    for ($i = 0; $i < count($pages); $i++) {
                        $pageNo = (string) ($i + 1);
                        $ovp = $ovPages[$pageNo] ?? null;
                        if (!$ovp || !is_array($ovp)) continue;
                        if (!empty($ovp['templateId'])) {
                            $pages[$i]['templateId'] = (string) $ovp['templateId'];
                        }
                        if (isset($ovp['items']) && is_array($ovp['items'])) {
                            foreach ($ovp['items'] as $oit) {
                                $slotIdx = (int) ($oit['slotIndex'] ?? -1);
                                if ($slotIdx >= 0) {
                                    // Map relative x/y/width/height overrides to slot rects for generic template
                                    foreach (['x','y','width','height'] as $k) {
                                        if (array_key_exists($k, $oit) && isset($pages[$i]['slots'][$slotIdx])) {
                                            $pages[$i]['slots'][$slotIdx][substr($k,0,1)] = (float) $oit[$k]; // x->x, y->y, width->w, height->h
                                            if ($k === 'width') $pages[$i]['slots'][$slotIdx]['w'] = (float) $oit[$k];
                                            if ($k === 'height') $pages[$i]['slots'][$slotIdx]['h'] = (float) $oit[$k];
                                        }
                                    }
                                    // Find matching item by slotIndex
                                    if (isset($pages[$i]['items']) && is_array($pages[$i]['items'])) {
                                        foreach ($pages[$i]['items'] as $j => $pit) {
                                            $si = (int) ($pit['slotIndex'] ?? 0);
                                            if ($si === $slotIdx) {
                                                // Apply visual overrides
                                                foreach (['caption','objectPosition','crop','scale','rotate'] as $k) {
                                                    if (array_key_exists($k, $oit)) {
                                                        $pages[$i]['items'][$j][$k] = $oit[$k];
                                                    }
                                                }
                                                // Apply photo override if provided
                                                if (isset($oit['photo']) && is_array($oit['photo'])) {
                                                    $pages[$i]['items'][$j]['photo'] = $oit['photo'];
                                                }
                                                // Apply src override if provided; map /photobook/asset/{hash}/{rel} to local cache file
                                                if (!empty($oit['src']) && is_string($oit['src'])) {
                                                    $src = (string) $oit['src'];
                                                    $hash = sha1($folder);
                                                    if (preg_match('#/photobook/asset/' . preg_quote($hash, '#') . '/(.+)$#', $src, $m)) {
                                                        $rel = $m[1];
                                                        $local = storage_path('app/pdf-exports/_cache/' . $hash . '/' . $rel);
                                                        // Prefer local file if exists, else keep URL
                                                        $pages[$i]['items'][$j]['src'] = is_file($local) ? ('file://' . $local) : $src;
                                                    } else {
                                                        $pages[$i]['items'][$j]['src'] = $src;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            logger()->debug('PB: overrides merge skipped', ['err' => $e->getMessage()]);
        }

        $updateProgress(60, 'Applying editor overrides...');

        $t = microtime(true);
        $updateProgress(70, 'Rendering ' . count($pages) . ' pages to HTML...');
        [$html, $assetsDir] = $builder->render($pages, $this->options);
        logger()->info('PB: builder rendered', [
            'html_kb' => round(strlen($html) / 1024, 1),
            'assetsDir' => $assetsDir,
            'secs' => round(microtime(true) - $t, 2),
            'mem_mb' => round(memory_get_usage(true) / 1048576, 1),
        ]);

        $name = 'book-' . now()->format('Ymd-His') . '.pdf';
        $t = microtime(true);
        $updateProgress(85, 'Generating PDF (this may take a moment)...');

        // Load persisted print settings from JSON file (not just config, which doesn't persist to queue worker)
        $persistedSettings = [];
        $settingsPath = storage_path('app/photobook-settings.json');
        if (is_file($settingsPath)) {
            $persistedSettings = json_decode(file_get_contents($settingsPath), true) ?: [];
        }
        $persistedPrint = $persistedSettings['print'] ?? [];

        // Build print options for renderer - persisted settings override config
        $printConfig = config('photobook.print', []);
        $printOptions = [
            'enabled' => (bool) ($persistedPrint['enabled'] ?? $printConfig['enabled'] ?? false) || !empty($this->options['print_mode']),
            'bleed_mm' => (float) ($persistedPrint['bleed_mm'] ?? $printConfig['bleed_mm'] ?? 3.0),
            'crop_marks' => (bool) ($persistedPrint['crop_marks'] ?? $printConfig['crop_marks'] ?? true),
            'spine_margin_mm' => (float) ($persistedPrint['spine_margin_mm'] ?? $printConfig['spine_margin_mm'] ?? 10.0),
        ];
        
        logger()->info('PB: print options', $printOptions);

        $pdf->renderTo($name, $html, $paper, $orientation, $dpi, $printOptions);
        $renderSecs = round(microtime(true) - $t, 2);

        $peak = round(memory_get_peak_usage(true) / 1048576, 1);
        logger()->info('Photobook generated at storage/app/pdf-exports/' . $name, [
            'render_secs' => $renderSecs,
            'total_secs' => round(microtime(true) - $jobStart, 2),
            'mem_peak_mb' => $peak,
            'print_mode' => $printOptions['enabled'],
        ]);
        $updateProgress(100, 'Complete! PDF saved as ' . $name, 'finished');
    }

    private function normalizeCoverOptions(array $options, array $coverMeta): array
    {
        $titleFromCover = $this->stringOrNull($coverMeta['title'] ?? null);
        if ($titleFromCover !== null && $this->stringOrNull($options['title'] ?? null) === null) {
            $options['title'] = $titleFromCover;
        }

        if ($this->stringOrNull($options['cover_image'] ?? null) === null) {
            $image = $this->stringOrNull($coverMeta['image'] ?? null);
            if ($image !== null) {
                $options['cover_image'] = $image;
            }
        }

        $subtitle = $this->stringOrNull($options['cover_subtitle'] ?? $options['subtitle'] ?? null);
        if ($subtitle === null) {
            $subtitle = $this->stringOrNull($coverMeta['cover_subtitle'] ?? $coverMeta['subtitle'] ?? null);
        }
        if ($subtitle !== null) {
            $options['cover_subtitle'] = $subtitle;
            $options['subtitle'] = $subtitle;
        } else {
            unset($options['cover_subtitle'], $options['subtitle']);
        }

        $date = $this->stringOrNull($options['cover_date'] ?? $options['date'] ?? null);
        if ($date === null) {
            $date = $this->stringOrNull($coverMeta['cover_date'] ?? $coverMeta['date'] ?? null);
        }
        if ($date !== null) {
            $options['cover_date'] = $date;
            $options['date'] = $date;
        } else {
            unset($options['cover_date'], $options['date']);
        }

        $show = $this->coerceBool($options['cover_show_date'] ?? null);
        if ($show === null) {
            $show = $this->coerceBool($coverMeta['cover_show_date'] ?? $coverMeta['show_date'] ?? null);
        }
        if ($show !== null) {
            $options['cover_show_date'] = $show;
            $options['show_date'] = $show;
        } else {
            unset($options['cover_show_date'], $options['show_date']);
        }

        $sourcePath = $this->stringOrNull($options['cover_source_path'] ?? ($coverMeta['sourcePath'] ?? null));
        if ($sourcePath !== null) {
            $options['cover_source_path'] = $sourcePath;
        } else {
            unset($options['cover_source_path']);
        }

        $align = $options['cover_align'] ?? $coverMeta['align'] ?? null;
        if (is_array($align)) {
            $options['cover_align'] = [
                'x' => $this->clampAlign($align['x'] ?? 0),
                'y' => $this->clampAlign($align['y'] ?? 0),
            ];
        } else {
            unset($options['cover_align']);
        }

        $offset = $options['cover_offset'] ?? $coverMeta['offset'] ?? null;
        if (is_array($offset)) {
            $options['cover_offset'] = [
                'x' => $this->coerceFloat($offset['x'] ?? 0.0, 0.0) ?? 0.0,
                'y' => $this->coerceFloat($offset['y'] ?? 0.0, 0.0) ?? 0.0,
            ];
        } else {
            unset($options['cover_offset']);
        }

        $zoomCandidate = $this->coercePositiveFloat($options['cover_zoom'] ?? null, null);
        if ($zoomCandidate === null) {
            $zoomCandidate = $this->coercePositiveFloat($coverMeta['zoom'] ?? $coverMeta['scale'] ?? null, null);
        }
        if ($zoomCandidate !== null) {
            $options['cover_zoom'] = $zoomCandidate;
        } else {
            unset($options['cover_zoom']);
        }

        if (!isset($options['cover_scale'])) {
            $scaleCandidate = $this->coercePositiveFloat($coverMeta['scale'] ?? null, null);
            if ($scaleCandidate !== null) {
                $options['cover_scale'] = $scaleCandidate;
            }
        }

        $rotationCandidate = $this->coerceFloat($options['cover_rotation'] ?? null, null);
        if ($rotationCandidate === null) {
            $rotationCandidate = $this->coerceFloat($coverMeta['rotation'] ?? $coverMeta['rotate'] ?? null, null);
        }
        if ($rotationCandidate !== null) {
            $options['cover_rotation'] = $rotationCandidate;
        } else {
            unset($options['cover_rotation']);
        }

        if (!isset($options['cover_rotate'])) {
            $rotateCandidate = $this->coerceFloat($coverMeta['rotate'] ?? null, null);
            if ($rotateCandidate !== null) {
                $options['cover_rotate'] = $rotateCandidate;
            }
        }

        $autoCandidate = $this->coerceBool($options['cover_auto'] ?? null);
        if ($autoCandidate === null) {
            $autoCandidate = $this->coerceBool($coverMeta['auto'] ?? null);
        }
        if ($autoCandidate !== null) {
            $options['cover_auto'] = $autoCandidate;
        } else {
            unset($options['cover_auto']);
        }

        $fitCandidate = $options['cover_fit'] ?? $coverMeta['fit'] ?? $coverMeta['crop'] ?? null;
        if (is_string($fitCandidate) && $fitCandidate !== '') {
            $options['cover_fit'] = strtolower($fitCandidate) === 'contain' ? 'contain' : 'cover';
        }

        if (!isset($options['cover_crop'])) {
            if (isset($coverMeta['crop'])) {
                $options['cover_crop'] = strtolower((string) $coverMeta['crop']) === 'contain' ? 'contain' : 'cover';
            } elseif (isset($options['cover_fit'])) {
                $options['cover_crop'] = $options['cover_fit'];
            }
        }

        $objectPos = $this->stringOrNull($options['cover_object_position'] ?? ($coverMeta['object_position'] ?? $coverMeta['objectPosition'] ?? null));
        if ($objectPos !== null) {
            $options['cover_object_position'] = $objectPos;
        } else {
            unset($options['cover_object_position']);
        }

        if (!isset($options['cover_image_web']) && isset($coverMeta['webSrc'])) {
            $options['cover_image_web'] = (string) $coverMeta['webSrc'];
        }

        return $options;
    }

    private function stringOrNull($value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            $trimmed = trim((string) $value);
            return $trimmed !== '' ? $trimmed : null;
        }
        return null;
    }

    private function coerceFloat($value, ?float $default = null): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        return $default;
    }

    private function coercePositiveFloat($value, ?float $default = null): ?float
    {
        $num = $this->coerceFloat($value, null);
        if ($num === null) {
            return $default;
        }
        return $num > 0 ? $num : $default;
    }

    private function coerceBool($value): ?bool
    {
        if (is_null($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((float) $value) != 0.0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === '') {
                return null;
            }
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        return null;
    }

    private function clampAlign($value): float
    {
        $num = $this->coerceFloat($value, 0.0) ?? 0.0;
        if ($num < -1.0) {
            return -1.0;
        }
        if ($num > 1.0) {
            return 1.0;
        }
        return $num;
    }
}