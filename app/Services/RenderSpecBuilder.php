<?php

namespace App\Services;

use App\DTO\RenderSpec;
use App\Models\PhotoFeature;

class RenderSpecBuilder
{
    /**
     * Build a RenderSpec for every assigned photo→slot pair.
     *
     * @param  array<int, array{path: string, slotId: string}> $assignment
     *         Each entry has at minimum 'path' (Nextcloud path) and 'slotId'.
     * @param  array<string, PhotoFeature>                     $featMap
     *         Keyed by Nextcloud path, as returned by FeatureRepository::getMany().
     * @return RenderSpec[]
     */
    public function build(array $assignment, array $featMap): array
    {
        $specs = [];

        foreach ($assignment as $entry) {
            $path   = $entry['path']   ?? '';
            $slotId = $entry['slotId'] ?? '';

            if ($path === '' || $slotId === '') {
                continue;
            }

            $feature = $featMap[$path] ?? null;
            $crop    = $feature?->suggested_crop;

            if (is_array($crop) && isset($crop['align'], $crop['zoom'])) {
                $specs[] = RenderSpec::fromSuggestedCrop($path, $slotId, $crop);
            } else {
                $specs[] = RenderSpec::neutral($path, $slotId);
            }
        }

        return $specs;
    }
}
