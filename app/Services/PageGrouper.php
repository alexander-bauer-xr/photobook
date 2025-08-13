<?php
// app/Services/PageGrouper.php

namespace App\Services;

use App\DTO\PhotoDto;

final class PageGrouper
{
    /** @param PhotoDto[] $photos sorted by takenAt or filename */
    public function group(array $photos, int $target=4): array
    {
        // Adaptive grouping: accumulate aspect-based weights until threshold ≈ target
        // Wider panoramas consume more capacity ⇒ fewer per page; squares/standard allow more.
        $pages = [];
        $cur = [];
    $cap = max(2, min(6, $target ?: 4));
    $cfg = config('photobook.grouper.hero');
    $freq = max(0, (int) ($cfg['frequency'] ?? 7));
    $arHigh = (float) ($cfg['extreme_ar_high'] ?? 2.2);
    $arLow  = (float) ($cfg['extreme_ar_low']  ?? 0.6);
    $qThr   = (float) ($cfg['quality_threshold'] ?? 0.9);
    $minMP  = (float) ($cfg['min_megapixels'] ?? 20.0);
        $sum = 0.0;
        $i = 0;
        foreach ($photos as $p) {
            $w = $p->width ?? null; $h = $p->height ?? null;
            $ar = ($w && $h && $h > 0) ? ($w / $h) : ($p->ratio ?: 1.0);
            // Weight heuristic (tweakable):
            // ultra-wide pano >= 2.2 => 2.5, wide 1.6..2.2 => 1.6, tall < 0.7 => 1.2, else 1.0
            if ($ar >= 2.2) $wgt = 2.5;
            elseif ($ar >= 1.6) $wgt = 1.6;
            elseif ($ar < 0.7) $wgt = 1.2;
            else $wgt = 1.0;

            // If current page nearly full, wrap before adding heavy pano to avoid singleton leftovers
            if (!empty($cur) && ($sum + $wgt) > $cap + 0.25) {
                $pages[] = $cur; $cur = []; $sum = 0.0;
            }

            // Occasionally force a 1-up hero page for high-quality or extreme aspect photos
            $isExtreme = ($ar >= $arHigh || $ar <= $arLow);
            $isHeroQuality = ($p->qualityScore !== null && $p->qualityScore >= $qThr) || (($w && $h) && ($w*$h >= $minMP * 1_000_000));
            if ($freq > 0 && empty($cur) && ($isExtreme || $isHeroQuality) && ($i % $freq === 0)) {
                $pages[] = [$p];
                $i++; // advance global index
                continue;
            }

            $cur[] = $p; $sum += $wgt;

            // Page break when capacity reached; keep soft max 6
            if ($sum >= $cap || count($cur) >= 6) {
                $pages[] = $cur; $cur = []; $sum = 0.0;
            }
            $i++;
        }
        if ($cur) $pages[] = $cur;

        // Balance: avoid trailing singletons if possible
        $n = count($pages);
        if ($n >= 2 && count($pages[$n-1]) === 1 && count($pages[$n-2]) >= 3) {
            $pages[$n-1] = array_merge(array_slice($pages[$n-2], -1), $pages[$n-1]);
            $pages[$n-2] = array_slice($pages[$n-2], 0, -1);
        }
        \Log::debug('Grouper: pages built', [
            'pages' => count($pages),
            'avg_size' => round(array_sum(array_map('count', $pages))/max(1,count($pages)),2),
        ]);
        return $pages;
    }
}
