<?php

namespace App\Services;

use App\Models\PhotoFeature;

class FeatureRepository
{
    /** @return array<string, PhotoFeature> */
    public function getMany(array $paths): array
    {
        if (empty($paths)) return [];
        return PhotoFeature::whereIn('path', $paths)->get()
            ->keyBy('path')->all();
    }

    /** @param array<string,mixed> $data */
    public function upsert(string $path, array $data): void
    {
        PhotoFeature::updateOrCreate(['path' => $path], $data);
    }

    /** Hamming distance for 64-bit hex pHash (16 hex chars) */
    public static function hamming(?string $a, ?string $b): ?int
    {
        if (!$a || !$b) return null;
        $a = strtolower($a); $b = strtolower($b);
        $a = preg_replace('/[^0-9a-f]/', '', $a);
        $b = preg_replace('/[^0-9a-f]/', '', $b);
        if (strlen($a) !== 16 || strlen($b) !== 16) return null;
        $xa = hex2bin($a); $xb = hex2bin($b);
        if ($xa === false || $xb === false) return null;
        $bits = 0;
        for ($i = 0; $i < strlen($xa); $i++) {
            $bits += self::countBits(ord($xa[$i]) ^ ord($xb[$i]));
        }
        return $bits;
    }

    private static function countBits(int $x): int
    {
        $c = 0; while ($x) { $x &= $x - 1; $c++; } return $c;
    }
}
