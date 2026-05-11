<?php

namespace App\Services;

use App\DTO\PhotoDto;
use App\Models\PhotoFeature; // phpcs:ignore

class CostMatrixBuilder
{
    /**
     * Build an n×n cost matrix for photo→slot assignment.
     *
     * cost(photo i, slot j) =
     *   wCrop        * aspectMismatch          (quadratic growth for extremes)
     * + wOrient      * orientationMismatch     (hard portrait↔landscape penalty)
     * + 2.0          * faceCropPenalty         (NEW v2: from suggested_crop)
     * + 0.8          * saliencyCropPenalty     (NEW v2: saliency vs slot centre)
     * - heroBonus    * heroinessScore * slotAreaFraction
     * - 0.3          * qualityBonus            (NEW v2: sharpness + aesthetic)
     * + wFlow        * chronologyPenalty
     *
     * @param  PhotoDto[]                    $photos   Ordered photos for one page
     * @param  array<int, array>             $slots    Slot definitions from template
     * @param  array<string, PhotoFeature>   $featMap  Keyed by photo path
     * @return float[][]                              n×n matrix, [photoIndex][slotIndex]
     */
    public function build(array $photos, array $slots, array $featMap): array
    {
        $n = count($photos);

        // Slot reading order (top→bottom, left→right) for chronology
        $slotOrder = [];
        foreach ($slots as $j => $s) {
            $slotOrder[$j] = $s['y'] + 0.6 * $s['x'];
        }
        asort($slotOrder);
        $slotRank = array_flip(array_keys($slotOrder)); // slotIndex → chronological rank

        // Slot area fractions for hero bonus
        $areas     = array_map(fn($s) => $s['w'] * $s['h'], $slots);
        $totalArea = array_sum($areas) ?: 1.0;
        $areaFrac  = array_map(fn($a) => $a / $totalArea, $areas);

        // Weights from config (with v1 defaults for backwards compat)
        $w         = config('photobook.planner.weights', []);
        $b         = config('photobook.planner.bonuses', []);
        $wCrop     = (float) ($w['crop']        ?? 1.0);
        $wOrient   = (float) ($w['orientation'] ?? 0.4);
        $wFlow     = (float) ($w['chronology']  ?? 0.25);
        $heroBonus = (float) ($b['hero_bonus']  ?? 0.3);

        $cost = array_fill(0, $n, array_fill(0, $n, 0.0));

        for ($i = 0; $i < $n; $i++) {
            $photo  = $photos[$i];
            $par    = $this->aspect($photo);
            $feat   = $featMap[$photo->path] ?? null;

            // --- Heroiness (faces + aesthetic) ---
            $heroScore = 0.0;
            if ($feat) {
                $faces = is_array($feat->faces ?? null) ? $feat->faces : [];
                if (!empty($faces)) {
                    $heroScore += 0.5;
                }
                $aes = $feat->aesthetic ?? null;
                if (is_numeric($aes)) {
                    $heroScore += min(0.5, max(0.0, ((float) $aes - 5.0) / 5.0));
                }
            }

            // --- Quality bonus (v2: sharpness + aesthetic combined) ---
            $qualityBonus = 0.0;
            if ($feat) {
                $sharpness = $feat->sharpness ?? null;
                if (is_numeric($sharpness)) {
                    $qualityBonus += min(0.5, log(1.0 + max(0.0, (float) $sharpness)) / 25.0);
                }
                $aes = $feat->aesthetic ?? null;
                if (is_numeric($aes)) {
                    $qualityBonus += min(0.5, max(0.0, ((float) $aes - 5.0) / 10.0));
                }
            }

            // --- Suggested crop (v2) ---
            $suggestedCrop = is_array($feat->suggested_crop ?? null) ? $feat->suggested_crop : null;
            $cropAlignX    = (float) ($suggestedCrop['align']['x'] ?? 0.0);
            $cropAlignY    = (float) ($suggestedCrop['align']['y'] ?? 0.0);

            // --- Saliency (v2) ---
            $saliency  = is_array($feat->saliency ?? null) ? $feat->saliency : null;
            $salCx     = (float) ($saliency['cx'] ?? 0.5);
            $salCy     = (float) ($saliency['cy'] ?? 0.5);

            for ($j = 0; $j < $n; $j++) {
                $slot = $slots[$j];
                $sar  = (float) ($slot['ar'] ?? $par);

                // 1. Aspect crop cost (quadratic)
                $cropCost  = abs($sar - $par);
                $cropCost *= 1.0 + 0.5 * $cropCost;

                // 2. Orientation hard mismatch
                $orientCost = (($sar < 0.95 && $par > 1.2) || ($sar > 1.2 && $par < 0.95)) ? 1.0 : 0.0;

                // 3. Face crop penalty (v2)
                //    If suggested_crop alignment is far from slot centre (0,0 = neutral), penalise
                $slotCx      = (float) ($slot['cx'] ?? 0.5);  // slot centre X in [0,1], default 0.5
                $slotCy      = (float) ($slot['cy'] ?? 0.5);
                $facePenalty = 0.0;
                if ($suggestedCrop !== null) {
                    // Distance between suggested alignment and slot's natural centre
                    $facePenalty = sqrt($cropAlignX ** 2 + $cropAlignY ** 2) * (1.0 - $areaFrac[$j]);
                }

                // 4. Saliency crop penalty (v2)
                //    Prefer placing saliency centre into larger slots
                $salPenalty = abs($salCx - $slotCx) + abs($salCy - $slotCy);
                $salPenalty *= (1.0 - $areaFrac[$j]);  // smaller slots are more forgiving

                // 5. Hero bonus
                $heroBns = $heroScore * ($areaFrac[$j] ?? 0.0) * $heroBonus;

                // 6. Quality bonus (v2)
                $qualBns = $qualityBonus * 0.3;

                // 7. Chronology
                $flowCost = abs($slotRank[$j] - $i) / max(1, $n - 1);

                $cost[$i][$j] =
                    $wCrop  * $cropCost
                    + $wOrient * $orientCost
                    + 2.0  * $facePenalty
                    + 0.8  * $salPenalty
                    - $heroBns
                    - $qualBns
                    + $wFlow   * $flowCost;
            }
        }

        return $cost;
    }

    private function aspect(PhotoDto $photo): float
    {
        if ($photo->ratio) {
            return $photo->ratio;
        }
        if ($photo->width && $photo->height && $photo->height > 0) {
            return $photo->width / $photo->height;
        }
        return 1.0;
    }
}
