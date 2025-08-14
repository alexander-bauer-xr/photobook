<?php

namespace App\Services;
use Illuminate\Support\Facades\Storage;

/**
 * Copilot prompt:
 * Build full HTML for the photo book using Blade partials:
 * - render(array $pages, array $options): [string $html, string $assetsDir]
 * - For now, use public URLs from Nextcloud (or temporary local copies later)
 * - Include a cover page and then loop pages with @include by template name
 */
class PhotoBookBuilder
{
    public function render(array $pages, array $options): array
    {
        if (function_exists('set_time_limit'))
            @set_time_limit(0);
        $t0 = microtime(true);
        \Log::info('Builder: start', [
            'pages' => count($pages),
            'mem_mb' => round(memory_get_usage(true) / 1048576, 1),
        ]);
        // Cache root per folder to reuse downloaded images
        $folder = $options['folder'] ?? config('photobook.folder');
        $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
        if (!empty($options['force_refresh'])) {
            $this->rrmdir($cacheRoot);
            \Log::info('Builder: cache invalidated', ['folder' => $folder]);
        }
        $imagesDir = $cacheRoot . DIRECTORY_SEPARATOR . 'images';
        if (!is_dir($imagesDir)) {
            @mkdir($imagesDir, 0775, true);
        }

    // Collect unique photos by path
        $unique = [];
        foreach ($pages as $page) {
            foreach ($page['photos'] as $p) {
                $unique[$p->path] = $p;
            }
        }
        // Build signature for current set of paths
        $paths = array_keys($unique);
        sort($paths);
        $signature = sha1(implode("\n", $paths));

        // Try to reuse manifest when unchanged
        $manifestFile = $cacheRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $map = [];
        $disk = Storage::disk('nextcloud');
        if (is_file($manifestFile)) {
            $manifest = json_decode(@file_get_contents($manifestFile), true) ?: [];
            if (!empty($manifest['signature']) && $manifest['signature'] === $signature && !empty($manifest['map']) && is_array($manifest['map'])) {
                // Validate files exist
                $allExist = true;
                foreach ($manifest['map'] as $p => $fname) {
                    $full = $imagesDir . DIRECTORY_SEPARATOR . $fname;
                    if (!is_file($full)) {
                        $allExist = false;
                        break;
                    }
                }
                if ($allExist) {
                    foreach ($manifest['map'] as $p => $fname) {
                        $map[$p] = $imagesDir . DIRECTORY_SEPARATOR . $fname;
                    }
                    \Log::info('Builder: cache reuse', ['folder' => $folder, 'count' => count($map)]);
                }
            }
        }

        // If no valid cache, (re)populate cache images for requested set
        if (empty($map)) {
            $total = count($unique);
            $idx = 0;
            $copied = 0;
            $skipped = 0;
            $errors = 0;
            $reused = 0;
            foreach ($unique as $path => $p) {
                $idx++;
                $ext = pathinfo($p->filename ?: basename($path), PATHINFO_EXTENSION);
                $fname = sha1($path) . ($ext ? ('.' . $ext) : '');
                $target = $imagesDir . DIRECTORY_SEPARATOR . $fname;
                if (is_file($target) && filesize($target) > 0) {
                    $map[$path] = $target;
                    $reused++;
                    continue;
                }
                try {
                    $stream = $disk->readStream($path);
                    if (!$stream) {
                        $skipped++;
                        continue;
                    }
                    if (is_resource($stream)) {
                        @stream_set_timeout($stream, 30);
                    }
                    $buf = '';
                    while (!feof($stream)) {
                        $chunk = @fread($stream, 16384);
                        if ($chunk === false) {
                            break;
                        }
                        if ($chunk !== '') {
                            $buf .= $chunk;
                        }
                    }
                    $meta = is_resource($stream) ? @stream_get_meta_data($stream) : [];
                    if (is_resource($stream)) {
                        @fclose($stream);
                    }
                    $timedOut = (bool) ($meta['timed_out'] ?? false);
                    if ($timedOut || $buf === '') {
                        if (is_file($target)) {
                            @unlink($target);
                        }
                        $errors++;
                        continue;
                    }

                    // Optional optimization (downscale + JPEG recompress)
                    $opt = config('photobook.optimize', []);
                    $doResize = !empty($opt['resize']);
                    $toJpeg = !empty($opt['convert_to_jpeg']);
                    $jpegQ = (int) ($opt['jpeg_quality'] ?? 75);

                    $finalPath = $target;
                    $imgData = $buf;
                    $srcExt = strtolower($ext);
                    // --- NEW: normalize rotation first (EXIF Orientation) ---
                    [$imgData, $srcExt] = $this->normalizeRotationFromBytes($imgData, $srcExt);

                    if ($doResize || $toJpeg) {
                        try {
                            [$w, $h] = @getimagesizefromstring($imgData) ?: [null, null];
                            if ($w && $h) {
                                $maxEdge = $opt['max_long_edge_px'] ?? null;
                                if (!$maxEdge) {
                                    // derive from paper + dpi: assume long edge full page
                                    $paper = ($options['paper'] ?? config('photobook.paper'));
                                    $dpi = (int) ($options['dpi'] ?? config('photobook.dpi'));
                                    $isLandscape = (($options['orientation'] ?? config('photobook.orientation')) === 'landscape');
                                    // A4: 8.27 x 11.69 inches; A3: 11.69 x 16.54 inches
                                    $inch = ($paper === 'a3') ? ($isLandscape ? 16.54 : 11.69) : ($isLandscape ? 11.69 : 8.27);
                                    $maxEdge = (int) round($inch * max(72, min(300, $dpi))); // cap dpi 300
                                }
                                $scale = 1.0;
                                $long = max($w, $h);
                                if ($long > $maxEdge) {
                                    $scale = $maxEdge / $long;
                                }
                                $tw = (int) max(1, round($w * $scale));
                                $th = (int) max(1, round($h * $scale));

                                // Now proceed with resize/convert using GD
                                $src = @imagecreatefromstring($imgData);
                                if ($src && $tw > 0 && $th > 0) {
                                    $dst = @imagecreatetruecolor($tw, $th);
                                    @imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
                                    // (rest of your JPEG/PNG writing stays the same)
                                    $encodeAsJpeg = $toJpeg || in_array($srcExt, ['heic', 'heif', 'webp', 'bmp', 'tiff', 'tif', 'png', 'gif']);
                                    if ($encodeAsJpeg) {
                                        $finalPath = preg_replace('/\.[a-z0-9]+$/i', '', $target) . '.jpg';
                                        @imagejpeg($dst, $finalPath, max(40, min(95, $jpegQ)));
                                    } else {
                                        // keep original extension
                                        if ($srcExt === 'png') {
                                            @imagesavealpha($dst, true);
                                            // PNG compression: 0 (no) - 9 (max). Map jpegQ 40..95 -> 6..1
                                            $level = (int) max(1, min(9, round((100 - max(40, min(95, $jpegQ))) / 10)));
                                            @imagepng($dst, $target, $level);
                                        } else {
                                            @imagejpeg($dst, $target, max(40, min(95, $jpegQ)));
                                        }
                                    }
                                    if (is_file($finalPath)) {
                                        $map[$path] = $finalPath;
                                        $copied++;
                                    }
                                    if (is_resource($dst)) {
                                        @imagedestroy($dst);
                                    }
                                }
                                if (is_resource($src)) {
                                    @imagedestroy($src);
                                }
                            }
                        } catch (\Throwable $e) {
                            // fallback: write original bytes
                            @file_put_contents($target, $imgData);
                            if (is_file($target)) {
                                $map[$path] = $target;
                                $copied++;
                            }
                        }
                    } else {
                        // No optimization: write original bytes
                        @file_put_contents($target, $imgData);
                        if (is_file($target)) {
                            $map[$path] = $target;
                            $copied++;
                        }
                    }
                    if ($idx % 25 === 0) {
                        \Log::debug('Builder: copy progress', ['idx' => $idx, 'of' => $total, 'copied' => $copied, 'reused' => $reused, 'skipped' => $skipped, 'mem_mb' => round(memory_get_usage(true) / 1048576, 1)]);
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    if ($idx % 10 === 0) {
                        \Log::debug('Builder: copy error', ['path' => $path, 'err' => $e->getMessage()]);
                    }
                }
            }
            // Write manifest
            $manifest = [
                'folder' => $folder,
                'signature' => $signature,
                'map' => array_combine(array_keys($map), array_map(fn($full) => basename($full), array_values($map))),
                'created_at' => date(DATE_ATOM),
            ];
            @file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));
            \Log::info('Builder: cache updated', [
                'unique' => $total,
                'copied' => $copied,
                'reused' => $reused,
                'skipped' => $skipped,
                'errors' => $errors,
                'secs' => round(microtime(true) - $t0, 2),
                'mem_mb' => round(memory_get_usage(true) / 1048576, 1),
            ]);
        }

    // Build feature map (faces/saliency) once for focal points
        $featMap = [];
        try {
            if (config('photobook.ml.enable') && (config('photobook.ml.faces') || config('photobook.ml.saliency'))) {
                $featMap = app(\App\Services\FeatureRepository::class)->getMany(array_keys($unique));
        \Log::info('Builder: features fetched', ['available' => is_array($featMap) ? count($featMap) : 0]);
            }
        } catch (\Throwable $e) {
            \Log::debug('Builder: feature fetch skipped', ['err' => $e->getMessage()]);
            $featMap = [];
        }

        // After we have $map and before rendering HTML, compute per-slot images
        [$pagePxW, $pagePxH] = $this->pagePixels(
            $options['paper'] ?? config('photobook.paper'),
            $options['orientation'] ?? config('photobook.orientation', 'landscape'),
            (int) ($options['target_dpi'] ?? config('photobook.optimize.target_dpi', 160))
        );
        $opt = config('photobook.optimize', []);
    $focalCache = [];
    $cntFaces = 0; $cntSaliency = 0; $cntFallback = 0;
    foreach ($pages as &$page) {
            $slots = $page['slots'] ?? [];
            if (!$slots) continue;
            $items = [];
            foreach (($page['items'] ?? []) as $it) {
                $p = $it['photo'] ?? null;
                if (!$p) { $items[] = $it; continue; }
                $origLocal = $map[$p->path] ?? null;
                if (!$origLocal) { $items[] = $it; continue; }

                $s = $slots[$it['slotIndex'] ?? 0] ?? null;
                if (!$s) { $items[] = $it; continue; }
                $targetW = max(1, (int) round(($s['w'] ?? 1.0) * $pagePxW));
                $targetH = max(1, (int) round(($s['h'] ?? 1.0) * $pagePxH));

                $ext = strtolower(pathinfo($origLocal, PATHINFO_EXTENSION) ?: 'jpg');
                $slotLocal = $this->buildSlotRender($origLocal, $ext, $targetW, $targetH, $opt);

                // Use file:/// for Dompdf
                $file = realpath($slotLocal) ?: $slotLocal;
                $it['src'] = 'file:///' . str_replace('\\', '/', $file);
                // Determine focal point (0..1) once per source and set CSS object-position
                // Respect planner-provided objectPosition if it is meaningful (not default "50% 50%")
                $hasPlannerPos = isset($it['objectPosition']) && trim((string)$it['objectPosition']) !== '' && trim((string)$it['objectPosition']) !== '50% 50%';
                if (!$hasPlannerPos) {
                    $used = false;
                    // 1) If ML features (faces/saliency) available for this path, prefer them
                    $feat = $featMap[$p->path] ?? null;
                    if ($feat) {
                        if (config('photobook.ml.enable') && config('photobook.ml.faces') && !empty($feat->faces) && is_array($feat->faces)) {
                            $faces = $feat->faces;
                            usort($faces, function($A,$B){
                                $a = (float) (($A['w'] ?? 0) * ($A['h'] ?? 0));
                                $b = (float) (($B['w'] ?? 0) * ($B['h'] ?? 0));
                                return $b <=> $a;
                            });
                            $cx = (float) ($faces[0]['cx'] ?? 0.5);
                            $cy = (float) ($faces[0]['cy'] ?? 0.5);
                            $cx = max(0.0, min(1.0, $cx));
                            $cy = max(0.0, min(1.0, $cy));
                            $it['objectPosition'] = ((int) round($cx * 100)) . '% ' . ((int) round($cy * 100)) . '%';
                            $used = true; $cntFaces++;
                        } elseif (config('photobook.ml.enable') && config('photobook.ml.saliency') && !empty($feat->saliency) && is_array($feat->saliency)) {
                            $cx = (float) ($feat->saliency['cx'] ?? 0.5);
                            $cy = (float) ($feat->saliency['cy'] ?? 0.5);
                            $cx = max(0.0, min(1.0, $cx));
                            $cy = max(0.0, min(1.0, $cy));
                            $it['objectPosition'] = ((int) round($cx * 100)) . '% ' . ((int) round($cy * 100)) . '%';
                            $used = true; $cntSaliency++;
                        }
                    }
                    // 2) Fallback to EXIF SubjectArea / entropy
                    if (!$used) {
                        if (!isset($focalCache[$origLocal])) {
                            $focalCache[$origLocal] = $this->detectFocalPointForFile($origLocal);
                        }
                        [$fx, $fy] = $focalCache[$origLocal];
                        $it['objectPosition'] = ((int) round($fx * 100)) . '% ' . ((int) round($fy * 100)) . '%';
                        $cntFallback++;
                    }
                }
                $items[] = $it;
            }
            $page['items'] = $items;
        }
        unset($page);

        \Log::info('Builder: focal source summary', [
            'faces' => $cntFaces,
            'saliency' => $cntSaliency,
            'fallback' => $cntFallback,
        ]);

        // Helper for Blade to build file:// URLs that Dompdf can read
        $asset_url = function ($photo) use ($map) {
            $path = $photo->path ?? (is_array($photo) ? ($photo['path'] ?? null) : null);
            if (!$path || !isset($map[$path]))
                return '';
            $file = realpath($map[$path]) ?: $map[$path];
            $uri = 'file:///' . str_replace('\\', '/', $file);
            return $uri;
        };

        $html = view('photobook.layout', [
            'options' => $options,
            'pages' => $pages,
            'assetsDir' => $imagesDir,
            'asset_url' => $asset_url,
            'show_form' => false,
            'defaults' => [
                'folder' => config('photobook.folder'),
                'paper' => config('photobook.paper'),
                'dpi' => (int) config('photobook.dpi'),
            ],
        ])->render();

        \Log::debug('Builder: html built', ['kb' => round(strlen($html) / 1024, 1)]);

        return [$html, $imagesDir];
    }

    /**
     * Rotate/flip JPEGs according to EXIF Orientation. Returns [bytes, ext].
     * For non-JPEGs or when EXIF unavailable, returns original.
     */
    private function normalizeRotationFromBytes(string $bytes, ?string $ext): array
    {
        // Allow disabling via config
        if (!config('photobook.normalize.exif_orientation', true)) {
            return [$bytes, strtolower((string) $ext)];
        }
        $ext = strtolower((string) $ext);
        // Only JPEG has reliable EXIF Orientation
        if (!in_array($ext, ['jpg', 'jpeg'])) {
            return [$bytes, $ext];
        }

        $orientation = $this->readExifOrientation($bytes);
        if ($orientation === 1 || $orientation === null) {
            return [$bytes, $ext]; // already upright or no EXIF
        }

        $img = @imagecreatefromstring($bytes);
        if (!$img)
            return [$bytes, $ext];

        // Apply orientation transforms (Exif 1..8)
        switch ($orientation) {
            case 2:
                $img = $this->imageFlip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $img = imagerotate($img, 180, 0);
                break;
            case 4:
                $img = $this->imageFlip($img, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $img = $this->imageFlip($img, IMG_FLIP_HORIZONTAL);
                $img = imagerotate($img, 270, 0);
                break;
            case 6:
                $img = imagerotate($img, -90, 0);
                break;   // 90 CW
            case 7:
                $img = $this->imageFlip($img, IMG_FLIP_HORIZONTAL);
                $img = imagerotate($img, -90, 0);
                break;
            case 8:
                $img = imagerotate($img, 90, 0);
                break;    // 270 CW
        }

        // Re-encode as JPEG (keeps pipeline simple)
        ob_start();
        @imagejpeg($img, null, 90);
        $out = (string) ob_get_clean();
        if (is_resource($img))
            @imagedestroy($img);

        return [$out ?: $bytes, 'jpg'];
    }

    /** Read EXIF Orientation (1..8) from raw JPEG bytes. */
    private function readExifOrientation(string $bytes): ?int
    {
        if (!function_exists('exif_read_data'))
            return null;
        $uri = 'data://image/jpeg;base64,' . base64_encode($bytes);
        $exif = @exif_read_data($uri, 'IFD0', true, false);
        if (!$exif)
            return null;

        foreach (['IFD0', 'EXIF', ''] as $ns) {
            if (isset($exif[$ns]['Orientation'])) {
                $o = (int) $exif[$ns]['Orientation'];
                return ($o >= 1 && $o <= 8) ? $o : null;
            }
        }
        return null;
    }

    /** Polyfill imageflip for older GD if needed */
    private function imageFlip($img, int $mode)
    {
        if (function_exists('imageflip')) {
            imageflip($img, $mode);
            return $img;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $dst = imagecreatetruecolor($w, $h);
        if ($mode === IMG_FLIP_HORIZONTAL) {
            for ($x = 0; $x < $w; $x++)
                imagecopy($dst, $img, $w - $x - 1, 0, $x, 0, 1, $h);
        } elseif ($mode === IMG_FLIP_VERTICAL) {
            for ($y = 0; $y < $h; $y++)
                imagecopy($dst, $img, 0, $h - $y - 1, 0, $y, $w, 1);
        }
        return $dst;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir))
            return;
        $items = scandir($dir);
        foreach ($items as $it) {
            if ($it === '.' || $it === '..')
                continue;
            $path = $dir . DIRECTORY_SEPARATOR . $it;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function pagePixels(string $paper, string $orientation, int $dpi): array
    {
        // mm sizes
        $sizes = [
            'a4' => [210, 297],
            'a3' => [297, 420],
        ];
        [$mmW, $mmH] = $sizes[strtolower($paper)] ?? $sizes['a4'];
        if ($orientation === 'landscape') {
            [$mmW, $mmH] = [$mmH, $mmW];
        }
        $inW = $mmW / 25.4;
        $inH = $mmH / 25.4;
        // cap practical DPI to ~200 for size sanity
        $dpi = max(120, min(220, $dpi));
        return [(int) round($inW * $dpi), (int) round($inH * $dpi)];
    }

    /** Compute focal point from file via EXIF SubjectArea, else edge entropy center. */
    private function detectFocalPointForFile(string $path): array
    {
        // Try EXIF SubjectArea (x,y[,diameter or w,h])
        if (function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($path, null, true, false);
                if ($exif) {
                    foreach (['IFD0','EXIF',''] as $ns) {
                        if (!empty($exif[$ns]['SubjectArea'])) {
                            $sa = $exif[$ns]['SubjectArea'];
                            if (is_string($sa)) { $sa = preg_split('/[,\s]+/', trim($sa)); }
                            $vals = array_values(array_map('intval', (array) $sa));
                            if (count($vals) >= 2) {
                                // Need image size to normalize
                                [$w,$h] = @getimagesize($path) ?: [null,null];
                                if ($w && $h) {
                                    $fx = max(0.0, min(1.0, $vals[0] / $w));
                                    $fy = max(0.0, min(1.0, $vals[1] / $h));
                                    return [$fx, $fy];
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }
        // Fallback: entropy center on a small thumbnail
        $bytes = @file_get_contents($path);
        if ($bytes === false) return [0.5, 0.5];
        $img = @imagecreatefromstring($bytes);
        if (!$img) return [0.5, 0.5];
        $fp = $this->entropyFocalPoint($img);
        if (is_resource($img)) @imagedestroy($img);
        return $fp;
    }

    /** Compute edge-energy centroid on a ~64px-wide grayscale thumbnail. */
    private function entropyFocalPoint($src): array
    {
        $sw = imagesx($src); $sh = imagesy($src);
        if ($sw <= 0 || $sh <= 0) return [0.5, 0.5];
        $tw = 64; $th = max(8, (int) round($sh * ($tw / $sw)));
        $thumb = imagecreatetruecolor($tw, $th);
        @imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
        $sumW = 0.0; $sumX = 0.0; $sumY = 0.0;
        for ($y = 1; $y < $th - 1; $y++) {
            for ($x = 1; $x < $tw - 1; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $rgbL = imagecolorat($thumb, $x-1, $y);
                $rgbR = imagecolorat($thumb, $x+1, $y);
                $rgbU = imagecolorat($thumb, $x, $y-1);
                $rgbD = imagecolorat($thumb, $x, $y+1);
                $l = (($rgb>>16)&255)*0.299 + (($rgb>>8)&255)*0.587 + ($rgb&255)*0.114;
                $lL = (($rgbL>>16)&255)*0.299 + (($rgbL>>8)&255)*0.587 + ($rgbL&255)*0.114;
                $lR = (($rgbR>>16)&255)*0.299 + (($rgbR>>8)&255)*0.587 + ($rgbR&255)*0.114;
                $lU = (($rgbU>>16)&255)*0.299 + (($rgbU>>8)&255)*0.587 + ($rgbU&255)*0.114;
                $lD = (($rgbD>>16)&255)*0.299 + (($rgbD>>8)&255)*0.587 + ($rgbD&255)*0.114;
                $gx = abs($lR - $lL); $gy = abs($lD - $lU); $g = $gx + $gy;
                if ($g > 0) { $sumW += $g; $sumX += ($x + 0.5) * $g; $sumY += ($y + 0.5) * $g; }
            }
        }
        if (is_resource($thumb)) @imagedestroy($thumb);
        if ($sumW <= 0) return [0.5, 0.5];
        $fx = $sumX / ($sumW * $tw); $fy = $sumY / ($sumW * $th);
        return [max(0.0, min(1.0, $fx)), max(0.0, min(1.0, $fy))];
    }
    private function buildSlotRender(string $srcPathLocal, string $ext, int $targetW, int $targetH, array $opt): string
    {
        $rendersDir = dirname($srcPathLocal, 2) . '/renders';
        if (!is_dir($rendersDir))
            @mkdir($rendersDir, 0775, true);

        // Include mtime as a weak stand-in for source versioning (since etag is remote)
        $mtime = @filemtime($srcPathLocal) ?: 0;
        $key = sha1($srcPathLocal . '|' . $mtime . '|' . $targetW . 'x' . $targetH . '|' . ($opt['jpeg_quality'] ?? 72));
        $out = "$rendersDir/$key.jpg";
        if (is_file($out) && filesize($out) > 0)
            return $out;

        $bytes = @file_get_contents($srcPathLocal);
        if ($bytes === false)
            return $srcPathLocal;

        // 1) normalize rotation for JPEGs
        [$bytes, $ext] = $this->normalizeRotationFromBytes($bytes, $ext);

        // 2) decode
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            @file_put_contents($out, $bytes);
            return $out;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(($targetW * ($opt['safety_scale'] ?? 1.15)) / $w, ($targetH * ($opt['safety_scale'] ?? 1.15)) / $h);
        $scale = min(1.0, $scale); // never upscale
        $tw = max(1, (int) floor($w * $scale));
        $th = max(1, (int) floor($h * $scale));

        $dst = imagecreatetruecolor($tw, $th);

        // If original has alpha and config allows, flatten to white (smaller, JPEG-friendly)
        $isPng = in_array(strtolower($ext), ['png']);
        if ($isPng && !empty($opt['flatten_png_to_white'])) {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        if (!empty($opt['progressive_jpeg']))
            imageinterlace($dst, 1);
        $q = max(40, min(92, (int) ($opt['jpeg_quality'] ?? 72)));
        imagejpeg($dst, $out, $q);

        if (is_resource($dst))
            imagedestroy($dst);
        if (is_resource($src))
            imagedestroy($src);
        return $out;
    }

}