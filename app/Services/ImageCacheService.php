<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageCacheService
{
    private const CACHE_BASE = 'pdf-exports/_cache';

    public function ensureCached(string $nextcloudPath): string
    {
        $folder = dirname($nextcloudPath);
        $cacheRoot = $this->cacheRoot($folder);
        $imagesDir = $cacheRoot . '/images';

        if (!is_dir($imagesDir)) {
            @mkdir($imagesDir, 0755, true);
        }

        $manifestFile = $cacheRoot . '/manifest.json';
        $manifest = $this->readManifest($manifestFile);

        if (isset($manifest['map'][$nextcloudPath])) {
            $local = $imagesDir . '/' . $manifest['map'][$nextcloudPath];
            if (is_file($local)) {
                return $local;
            }
        }

        $local = $this->downloadOne($nextcloudPath, $imagesDir);

        $manifest['map'][$nextcloudPath] = basename($local);
        $manifest['signature'] = '';
        @file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));

        return $local;
    }

    public function ensureCachedBatch(array $photos): array
    {
        if (empty($photos)) return [];

        $unique = [];
        foreach ($photos as $photo) {
            $path = is_array($photo) ? ($photo['path'] ?? null) : ($photo->path ?? null);
            if ($path) {
                $unique[$path] = $photo;
            }
        }

        $firstPath = array_key_first($unique);
        $folder = dirname($firstPath);
        $cacheRoot = $this->cacheRoot($folder);
        $imagesDir = $cacheRoot . '/images';

        if (!is_dir($imagesDir)) {
            @mkdir($imagesDir, 0755, true);
        }

        $manifest = $this->buildManifest($photos, $cacheRoot);
        $manifestFile = $cacheRoot . '/manifest.json';

        $existing = $this->readManifest($manifestFile);
        if (
            !empty($existing['signature'])
            && $existing['signature'] === $manifest['signature']
            && !empty($existing['map'])
        ) {
            $allExist = true;
            foreach ($existing['map'] as $p => $fname) {
                if (!is_file($imagesDir . '/' . $fname)) {
                    $allExist = false;
                    break;
                }
            }
            if ($allExist) {
                Log::info('ImageCacheService: cache hit', ['count' => count($existing['map'])]);
                return array_map(
                    fn($fname) => $imagesDir . '/' . $fname,
                    $existing['map']
                );
            }
        }

        $map = [];
        $copied = $reused = $skipped = $errors = 0;

        foreach ($unique as $path => $p) {
            $filename = is_array($p) ? ($p['filename'] ?? basename($path)) : ($p->filename ?? basename($path));
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $fname = sha1($path) . ($ext ? '.' . $ext : '');
            $target = $imagesDir . '/' . $fname;

            if (is_file($target) && filesize($target) > 0) {
                $map[$path] = $target;
                $reused++;
                continue;
            }

            try {
                $local = $this->downloadOne($path, $imagesDir, $fname);
                $map[$path] = $local;
                $copied++;
            } catch (\Throwable $e) {
                Log::warning('ImageCacheService: download failed', [
                    'path' => $path,
                    'err'  => $e->getMessage(),
                ]);
                $errors++;
                $skipped++;
            }
        }

        $manifest['map'] = array_combine(
            array_keys($map),
            array_map(fn($full) => basename($full), array_values($map))
        );
        @file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));

        Log::info('ImageCacheService: cache updated', compact('copied', 'reused', 'skipped', 'errors'));

        return $map;
    }

    public function buildManifest(array $photos, string $cacheDir): array
    {
        $paths = [];
        foreach ($photos as $photo) {
            $path = is_array($photo) ? ($photo['path'] ?? null) : ($photo->path ?? null);
            if ($path) {
                $paths[] = $path;
            }
        }
        sort($paths);

        return [
            'signature' => sha1(implode("\n", $paths)),
            'map' => [],
            'created_at' => date(DATE_ATOM),
        ];
    }

    public function invalidate(string $folder): void
    {
        $cacheRoot = $this->cacheRoot($folder);
        $this->rrmdir($cacheRoot);
        Log::info('ImageCacheService: cache invalidated', ['folder' => $folder]);
    }

    private function cacheRoot(string $folder): string
    {
        return storage_path(self::CACHE_BASE . '/' . sha1($folder));
    }

    private function downloadOne(string $path, string $imagesDir, ?string $fname = null): string
    {
        $ext = pathinfo(basename($path), PATHINFO_EXTENSION);
        $fname = $fname ?? (sha1($path) . ($ext ? '.' . $ext : ''));
        $target = $imagesDir . '/' . $fname;

        $disk = Storage::disk('nextcloud');
        $stream = $disk->readStream($path);

        if (!$stream) {
            throw new \RuntimeException("Could not open stream for: {$path}");
        }

        if (is_resource($stream)) {
            @stream_set_timeout($stream, 30);
        }

        $buf = '';
        while (!feof($stream)) {
            $chunk = @fread($stream, 16384);
            if ($chunk === false) break;
            if ($chunk !== '') $buf .= $chunk;
        }

        $meta     = is_resource($stream) ? @stream_get_meta_data($stream) : [];
        $timedOut = (bool) ($meta['timed_out'] ?? false);

        if (is_resource($stream)) {
            @fclose($stream);
        }

        if ($timedOut || $buf === '') {
            throw new \RuntimeException("Stream timeout or empty response for: {$path}");
        }

        @file_put_contents($target, $buf);

        if (!is_file($target)) {
            throw new \RuntimeException("Failed to write cache file: {$target}");
        }

        return $target;
    }

    private function readManifest(string $path): array
    {
        if (!is_file($path)) return [];
        return json_decode(@file_get_contents($path), true) ?: [];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $it) {
            if ($it === '.' || $it === '..') continue;
            $full = $dir . '/' . $it;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
