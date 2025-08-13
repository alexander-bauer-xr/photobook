<?php

namespace App\Services;

use App\DTO\PhotoDto;
use Illuminate\Support\Facades\Cache;
use League\Flysystem\FilesystemOperator;

/**
 * Copilot prompt:
 * Implement ImageProbe to enrich PhotoDto with width/height/ratio using Intervention Image.
 * - Inject nextcloud FilesystemOperator and cache
 * - fillDimensions(PhotoDto[]): PhotoDto[]
 * - Try to stream bytes and use exif_imagetype/getimagesizefromstring
 * - Cache by key "probe:{path}" for 1 day
 */
class ImageProbe
{
    public function __construct(private FilesystemOperator $disk, private ?WebDavClient $dav = null) {
        $this->dav ??= new WebDavClient();
    }

    /** @param PhotoDto[] $photos */
    public function fillDimensions(array $photos): array
    {
        $start = microtime(true);
        $n = count($photos);
        \Log::info('Probe: start', ['count' => $n]);
    $i = 0; $dimmed = 0; $fail = 0; $exifHits = 0; $nameHits = 0; $partialUsed = 0;
    $out = array_map(function(PhotoDto $p) use (&$i, &$dimmed, &$fail, &$exifHits, &$nameHits, &$partialUsed) {
            $idx = $i++;
            $etag = $p->etag ?? null; // present if listed via WebDAV
            $key = 'probe:'.sha1($p->path.'|'.($etag ?: 'noetag'));
            $meta = Cache::remember($key, 86400, function() use ($p, $idx, &$fail, &$exifHits, &$nameHits, &$partialUsed) {
                try {
                    // Prefer partial GET via WebDAV to avoid downloading full files
                    $bytes = '';
            if ($this->dav) {
                        $resp = $this->dav->getPartial($p->path, 0, 65535);
                        if (!empty($resp['bytes'])) {
                            $bytes = $resp['bytes'];
                $partialUsed++;
                        }
                    }
                    if ($bytes === '') {
                        $stream = $this->disk->readStream($p->path);
                        if (!$stream) { $fail++; return null; }
                        try {
                            $bytes = stream_get_contents($stream, 65536) ?: '';
                        } finally {
                            if (is_resource($stream)) @fclose($stream);
                        }
                    }
                    if (!$bytes) return null;
                    $info = @getimagesizefromstring($bytes);
                    $w = $info[0] ?? null; $h = $info[1] ?? null;
                    $takenAt = null;
            if (function_exists('exif_read_data')) {
                        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes), 'EXIF', true, false);
                        $ts = $exif['EXIF']['DateTimeOriginal'] ?? $exif['IFD0']['DateTime'] ?? null;
                        if ($ts) {
                            try { $takenAt = new \DateTimeImmutable(str_replace(':','-',substr($ts,0,10)).substr($ts,10)); } catch (\Throwable $e) {}
                if ($takenAt) $exifHits++;
                        }
                    }
                    // Fallback: parse date from filename like 20230902_142900 or 2023-09-02 14-29-00
                    if (!$takenAt) {
                        $name = $p->filename;
                        if (preg_match('/(20\d{2})[-_]?([01]\d)[-_]?([0-3]\d)[ _-]?([0-2]\d)[-_]?([0-5]\d)[-_]?([0-5]\d)/', $name, $m)) {
                            $iso = sprintf('%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
                            try { $takenAt = new \DateTimeImmutable($iso); } catch (\Throwable $e) {}
                            if ($takenAt) $nameHits++;
                        }
                    }
                    // Simple quality score: normalized by megapixels and partial bytes seen
                    $mp = ($w && $h) ? ($w*$h/1000000.0) : 0.0;
                    $q = $mp > 0 ? min(1.0, 0.5 + 0.5*min($mp/12.0, 1.0)) : 0.0; // 0..1, favoring >=12MP
                    return [
                        'w' => $w, 'h' => $h,
                        'takenAt' => $takenAt?->format(DATE_ATOM),
                        'qualityScore' => $q,
                    ];
                } catch (\Throwable $e) {
                    if ($idx % 25 === 0) { \Log::debug('Probe: error', ['path' => $p->path, 'err' => $e->getMessage()]); }
                    return null;
                }
            });
            if (empty($meta) || !isset($meta['w'], $meta['h'])) {
                if ($idx % 50 === 0) { \Log::debug('Probe: no meta', ['idx' => $idx, 'path' => $p->path]); }
                return $p;
            }
            $dimmed++;
            return PhotoDto::fromArray([
                'path' => $p->path,
                'filename' => $p->filename,
                'mime' => $p->mime,
                'width' => $meta['w'],
                'height' => $meta['h'],
                'ratio' => ($meta['h'] ?? 0) ? round(($meta['w']/$meta['h']), 4) : null,
                'takenAt' => $meta['takenAt'] ? new \DateTimeImmutable($meta['takenAt']) : $p->takenAt,
                'qualityScore' => $meta['qualityScore'] ?? $p->qualityScore,
                'fileSize' => $p->fileSize,
            ]);
        }, $photos);
        \Log::info('Probe: done', [
            'count' => $n,
            'with_dims' => $dimmed,
            'fail' => $fail,
            'exif_hits' => $exifHits,
            'filename_ts_hits' => $nameHits,
            'partial_used' => $partialUsed,
            'secs' => round(microtime(true) - $start, 2),
        ]);
        return $out;
    }
}