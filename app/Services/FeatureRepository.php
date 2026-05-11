<?php

namespace App\Services;

use App\Models\PhotoFeature;
use Illuminate\Support\Collection;

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
    public function upsert(string $path, array $data, string $analysis_version = 'v2'): void
    {
        PhotoFeature::updateOrCreate(
            ['path' => $path],
            array_merge($data, [
                'analysis_version' => $analysis_version,
                'analyzed_at'      => now(),
            ])
        );
    }

    /**
     * Return all records whose analysis_version is older than $sinceVersion.
     * Versions are compared as plain strings (v1 < v2 lexicographically).
     */
    public function getStale(string $sinceVersion): Collection
    {
        return PhotoFeature::where('analysis_version', '<', $sinceVersion)
            ->orWhereNull('analysis_version')
            ->get();
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
