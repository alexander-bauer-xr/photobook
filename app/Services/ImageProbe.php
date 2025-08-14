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
    public function __construct(
        private FilesystemOperator $disk,
        private ?WebDavClient $dav = null,
        private ?FeatureRepository $features = null,
    ) {
        $this->dav ??= new WebDavClient();
        $this->features ??= app(FeatureRepository::class);
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
                    // Try quick size detection from initial chunk
                    $info = @getimagesizefromstring($bytes);
                    $w = $info[0] ?? null; $h = $info[1] ?? null;
                    // If unknown, escalate: detect mime, fetch larger chunk, or try Imagick
                    if (!$w || !$h) {
                        $mime = null;
                        if (function_exists('finfo_open')) {
                            try {
                                $f = finfo_open(FILEINFO_MIME_TYPE);
                                if ($f) { $mime = @finfo_buffer($f, $bytes) ?: null; @finfo_close($f); }
                            } catch (\Throwable $e) {}
                        }
                        $needsMore = true;
                        // Try a larger partial (512 KB) for formats that often need more header/data
                        if ($this->dav) {
                            try {
                                $resp2 = $this->dav->getPartial($p->path, 0, 524288);
                                if (!empty($resp2['bytes'])) {
                                    $bytes = $resp2['bytes'];
                                    $info = @getimagesizefromstring($bytes);
                                    $w = $info[0] ?? null; $h = $info[1] ?? null;
                                    if ($w && $h) { $needsMore = false; }
                                }
                            } catch (\Throwable $e) {}
                        }
                        // Try Imagick if still unknown and extension available
                        if ($needsMore && class_exists('Imagick')) {
                            try {
                                $im = new \Imagick();
                                $im->readImageBlob($bytes);
                                $w = $im->getImageWidth();
                                $h = $im->getImageHeight();
                                $im->clear(); $im->destroy();
                                if ($w && $h) { $needsMore = false; }
                            } catch (\Throwable $e) {}
                        }
                        // Final fallback: read a bigger chunk from the stream (up to ~2 MB)
                        if ($needsMore) {
                            $stream = $this->disk->readStream($p->path);
                            if ($stream) {
                                try {
                                    $buf = '';
                                    $limit = 2*1024*1024; // 2 MB
                                    while (!feof($stream) && strlen($buf) < $limit) {
                                        $chunk = fread($stream, 131072); // 128 KB
                                        if ($chunk === false) break;
                                        if ($chunk !== '') $buf .= $chunk;
                                    }
                                    if ($buf !== '') {
                                        $bytes = $buf;
                                        $info = @getimagesizefromstring($bytes);
                                        $w = $info[0] ?? null; $h = $info[1] ?? null;
                                        if (!$w || !$h) {
                                            if (class_exists('Imagick')) {
                                                try {
                                                    $im = new \Imagick();
                                                    $im->readImageBlob($bytes);
                                                    $w = $im->getImageWidth();
                                                    $h = $im->getImageHeight();
                                                    $im->clear(); $im->destroy();
                                                } catch (\Throwable $e) {}
                                            }
                                        }
                                    }
                                } finally {
                                    if (is_resource($stream)) @fclose($stream);
                                }
                            }
                        }
                    }
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

                    // Compute tiny-ML features (PHP-only) if enabled
                    if (config('photobook.ml.enable')) {
                        $feat = [];
                        if (config('photobook.ml.sharpness')) {
                            $feat['sharpness'] = $this->laplacianVariance($bytes);
                        }
                        if (config('photobook.ml.phash')) {
                            $feat['phash'] = $this->phashHex64($bytes);
                        }
                        if (!empty($feat)) {
                            try { $this->features?->upsert($p->path, $feat); } catch (\Throwable $e) {
                                \Log::debug('Probe: feature upsert failed', ['path'=>$p->path, 'err'=>$e->getMessage()]);
                            }
                        }
                    }
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

    private function laplacianVariance(string $bytes): ?float
    {
        $im = @imagecreatefromstring($bytes);
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im);
        $scale = 256 / max(1, max($w, $h));
        if ($scale < 1) {
            $nw = max(1, (int)($w*$scale)); $nh = max(1, (int)($h*$scale));
            $tmp = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($tmp, $im, 0,0,0,0, $nw,$nh, $w,$h);
            imagedestroy($im); $im = $tmp; $w=$nw; $h=$nh;
        }
        $sum=0.0; $sum2=0.0; $n=0;
        for ($y=1; $y<$h-1; $y++) {
            for ($x=1; $x<$w-1; $x++) {
                $c = $this->grayAt($im,$x,$y)*4
                   - $this->grayAt($im,$x-1,$y)
                   - $this->grayAt($im,$x+1,$y)
                   - $this->grayAt($im,$x,$y-1)
                   - $this->grayAt($im,$x,$y+1);
                $sum += $c; $sum2 += $c*$c; $n++;
            }
        }
        if ($n <= 1) { imagedestroy($im); return null; }
        $mean = $sum/$n; $var = max(0.0, ($sum2/$n) - ($mean*$mean));
        imagedestroy($im);
        return $var;
    }

    private function grayAt($im, int $x, int $y): float
    {
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 255; $g = ($rgb >> 8) & 255; $b = $rgb & 255;
        return 0.299*$r + 0.587*$g + 0.114*$b;
    }

    private function phashHex64(string $bytes): ?string
    {
        $im = @imagecreatefromstring($bytes);
        if (!$im) return null;
        $tmp = imagecreatetruecolor(32, 32);
        imagecopyresampled($tmp, $im, 0,0,0,0, 32,32, imagesx($im), imagesy($im));
        imagedestroy($im);
        $px = [];
        for ($y=0; $y<32; $y++) {
            for ($x=0; $x<32; $x++) {
                $px[$y*32+$x] = $this->grayAt($tmp, $x, $y);
            }
        }
        imagedestroy($tmp);
        $dct = function($N, $arr) {
            $out = array_fill(0, $N, 0.0);
            for ($u=0; $u<$N; $u++) {
                $sum = 0.0;
                for ($x=0; $x<$N; $x++) {
                    $sum += $arr[$x] * cos((M_PI*$u*(2*$x+1))/(2*$N));
                }
                $alpha = ($u==0) ? sqrt(1.0/$N) : sqrt(2.0/$N);
                $out[$u] = $alpha * $sum;
            }
            return $out;
        };
        // Row DCT then column DCT for top-left 8x8
        $rows = [];
        for ($y=0; $y<32; $y++) { $rows[$y] = $dct(32, array_slice($px, $y*32, 32)); }
        $block = [];
        for ($u=0; $u<8; $u++) {
            $col = [];
            for ($y=0; $y<32; $y++) { $col[] = $rows[$y][$u]; }
            $block[$u] = $dct(32, $col);
        }
        $vals = [];
        for ($v=0; $v<8; $v++) {
            for ($u=0; $u<8; $u++) {
                if ($u==0 && $v==0) continue;
                $vals[] = $block[$u][$v];
            }
        }
        sort($vals);
        $median = $vals[(int) floor(count($vals)/2)] ?? 0.0;
        $hex = '';
        $acc = 0; $bitsInAcc = 0;
        for ($i=0; $i<64; $i++) {
            $u = $i % 8; $v = (int) floor($i/8);
            $val = ($u==0 && $v==0) ? 0 : $block[$u][$v];
            $bit = ($val > $median) ? 1 : 0;
            $acc = ($acc << 1) | $bit; $bitsInAcc++;
            if ($bitsInAcc === 4) {
                $hex .= dechex($acc & 0xF);
                $acc = 0; $bitsInAcc = 0;
            }
        }
        return str_pad($hex, 16, '0');
    }
}