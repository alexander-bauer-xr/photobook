<?php

namespace App\Jobs;

use App\Services\{NextcloudPhotoRepository, ImageProbe, LayoutPlanner, PhotoBookBuilder, PdfRenderer, PageGrouper, LayoutPlannerV2, LayoutTemplates, FeatureRepository};
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
        PhotoBookBuilder $builder,
        PdfRenderer $pdf
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
        $paper = $this->options['paper'] ?? Config::get('photobook.paper');
        $orientation = $this->options['orientation'] ?? Config::get('photobook.orientation', 'landscape');
        $dpi = (int) ($this->options['dpi'] ?? Config::get('photobook.dpi'));

        $t = microtime(true);
        $photos = $repo->listPhotos($folder);
        logger()->info('PB: repo listed photos', [
            'count' => count($photos),
            'secs' => round(microtime(true) - $t, 2),
            'mem_mb' => round(memory_get_usage(true) / 1048576, 1),
        ]);

        // Optional: limit for debugging large sets
        if (!empty($this->options['max_photos']) && is_numeric($this->options['max_photos'])) {
            $photos = array_slice($photos, 0, (int) $this->options['max_photos']);
            logger()->info('PB: limiting photos for debug', ['max' => (int) $this->options['max_photos']]);
        }

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
        usort($photos, function($a,$b) {
            $ta = $a->takenAt?->getTimestamp() ?? PHP_INT_MIN;
            $tb = $b->takenAt?->getTimestamp() ?? PHP_INT_MIN;
            return $ta <=> $tb ?: strcmp($a->filename, $b->filename);
        });

        // Optional dedupe burst by pHash within small time windows
    if (config('photobook.ml.enable') && config('photobook.ml.phash') && \Illuminate\Support\Facades\Schema::hasTable('photo_features')) {
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
            $t = microtime(true);
            $groups = $grouper->group($photos, 4);
            $pages = [];
            $recentTpls = [];
            // Build bias map from prior feedback (folder-scoped)
            $biasMap = [];
            try {
                $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
                $fbFile = $cacheRoot . DIRECTORY_SEPARATOR . 'feedback.log';
                if (is_file($fbFile)) {
                    $lines = @file($fbFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                    // Aggregate by template id for simple biasing; we need last built pages.json to map page->template
                    $pagesJson = $cacheRoot . DIRECTORY_SEPARATOR . 'pages.json';
                    $tplByPage = [];
                    if (is_file($pagesJson)) {
                        $pj = json_decode((string) @file_get_contents($pagesJson), true);
                        foreach ((array)($pj['pages'] ?? []) as $pinfo) {
                            $n = (int) ($pinfo['n'] ?? 0);
                            $tid = (string) ($pinfo['templateId'] ?? ($pinfo['template'] ?? ''));
                            if ($n >= 1 && $tid !== '') { $tplByPage[$n] = $tid; }
                        }
                    }
                    $acc = [];
                    foreach ($lines as $ln) {
                        $j = json_decode($ln, true);
                        if (!is_array($j)) continue;
                        if (!empty($j['folder']) && (string)$j['folder'] !== (string)$folder) continue;
                        $pg = (int) ($j['page'] ?? 0);
                        $act = (string) ($j['action'] ?? '');
                        $tid = $tplByPage[$pg] ?? null;
                        if ($pg < 1 || !$tid) continue;
                        $w = 0.0;
                        switch ($act) {
                            case 'like': $w = +0.10; break;
                            case 'dislike': $w = -0.20; break;
                            case 'faces-cropped': $w = -0.15; break;
                            case 'too-repetitive': $w = -0.15; break;
                            case 'low-confidence': $w = -0.05; break;
                            default: $w = 0.0; break;
                        }
                        if ($w !== 0.0) {
                            $acc[$tid] = ($acc[$tid] ?? 0.0) + $w;
                        }
                    }
                    // Clamp biases to reasonable bounds
                    foreach ($acc as $tid => $v) {
                        $biasMap[$tid] = max(-0.6, min(0.4, $v));
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
            // Load review overrides if present (latest entry for each page wins)
            $overridesByPage = [];
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
            } catch (\Throwable $e) {
                // ignore
            }
            foreach ($groups as $group) {
                $pageIndex = count($pages) + 1; // 1-based page index in review
                $overrideTpl = $overridesByPage[$pageIndex] ?? null;
                if ($overrideTpl) {
                    $choice = $plannerV2->chooseLayoutWithTemplate($group, $overrideTpl);
                } else {
                    $choice = $plannerV2->chooseLayout($group, ['recent' => $recentTpls, 'bias' => $biasMap]);
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
        } else {
            $t = microtime(true);
            $pages = $planner->plan($photos, $this->options);
            logger()->info('PB: planner pages', [
                'pages' => count($pages),
                'secs' => round(microtime(true) - $t, 2),
                'mem_mb' => round(memory_get_usage(true) / 1048576, 1),
            ]);
        }

        $t = microtime(true);
        [$html, $assetsDir] = $builder->render($pages, $this->options);
        logger()->info('PB: builder rendered', [
            'html_kb' => round(strlen($html) / 1024, 1),
            'assetsDir' => $assetsDir,
            'secs' => round(microtime(true) - $t, 2),
            'mem_mb' => round(memory_get_usage(true) / 1048576, 1),
        ]);

        $name = 'book-' . now()->format('Ymd-His') . '.pdf';
        $t = microtime(true);
        $pdf->renderTo($name, $html, $paper, $orientation, $dpi);
        $renderSecs = round(microtime(true) - $t, 2);

        $peak = round(memory_get_peak_usage(true) / 1048576, 1);
        logger()->info('Photobook generated at storage/app/pdf-exports/' . $name, [
            'render_secs' => $renderSecs,
            'total_secs' => round(microtime(true) - $jobStart, 2),
            'mem_peak_mb' => $peak,
        ]);
    }
}