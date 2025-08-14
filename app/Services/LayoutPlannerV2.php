<?php
// app/Services/LayoutPlannerV2.php

namespace App\Services;

use App\DTO\PhotoDto;

final class LayoutPlannerV2
{
    public function __construct(
        private LayoutTemplates $templates = new LayoutTemplates(),
        private ?FeatureRepository $featRepo = null,
    ) {
        $this->featRepo ??= app(FeatureRepository::class);
    }

    /**
     * @param PhotoDto[] $photosPage photos for one page (<= 6 ideal)
     * @return array{template:string, slots:array<int,array>, items:array<int,array>}
     *   slots[]: normalized rects from template
     *   items[]: ['photo'=>PhotoDto,'slotIndex'=>int,'crop'=>'cover','objectPosition'=>string]
     */
    /**
     * @param PhotoDto[] $photosPage
     * @param array{recent?:string[]} $context optional planner context (e.g., recent template ids)
     */
    public function chooseLayout(array $photosPage, array $context = []): array
    {
        $n = count($photosPage);
        $catalog = LayoutTemplates::all();
        $candidates = $catalog[$n] ?? $catalog[min(6, max(1,$n))];
    \Log::debug('PlannerV2: candidates', ['count' => count($candidates), 'n' => $n]);

        // Build photo aspect histogram
        $ph = ['tall'=>0,'wide'=>0,'sq'=>0];
        foreach ($photosPage as $p) {
            $ar = $this->aspect($p);
            if ($ar < 0.95) $ph['tall']++; elseif ($ar > 1.2) $ph['wide']++; else $ph['sq']++;
        }

        // Keep templates whose slot histogram is "compatible" (soft filter)
        $filtered = [];
        foreach ($candidates as $tpl) {
            $slots = $tpl['slots'];
            $sl = ['tall'=>0,'wide'=>0,'sq'=>0];
            foreach ($slots as $s) {
                $ar = $s['ar'] ?? 1.0; // neutral if not specified
                if ($ar < 0.95) $sl['tall']++; elseif ($ar > 1.2) $sl['wide']++; else $sl['sq']++;
            }
            // Soft filter: always keep, but compute a histogram mismatch used as penalty later
            $tpl['_hist_diff'] = abs($sl['tall']-$ph['tall']) + abs($sl['wide']-$ph['wide']);
            $filtered[] = $tpl;
        }
        if ($filtered) $candidates = $filtered;
        \Log::debug('PlannerV2: filtered candidates', ['count' => count($candidates), 'hist' => $ph]);

        // Score normally
        $best = null; $bestScore = -INF; $second = null; $secondScore = -INF;
        $var = config('photobook.planner.variety');
        $histPenalty = (float) ($var['hist_penalty'] ?? 0.12);
        $repeatPenalty = (float) ($var['repeat_penalty'] ?? 0.25);
        $recent = array_slice((array)($context['recent'] ?? []), -((int)($var['repeat_window'] ?? 6)));
        foreach ($candidates as $tpl) {
            $res = $this->scoreTemplate($tpl, $photosPage);
            // Apply histogram mismatch penalty (convert to score domain)
            $score = $res['score'] - $histPenalty * (float)($tpl['_hist_diff'] ?? 0);
            // Apply repeat penalty if same template used recently (per occurrence)
            $rep = 0; foreach ($recent as $r) { if ($r === ($tpl['id'] ?? '')) $rep++; }
            if ($rep > 0) { $score -= $repeatPenalty * $rep; }

            if ($score > $bestScore) {
                $second = $best; $secondScore = $bestScore;
                $bestScore = $score;
                $best = [
                    'template' => $tpl['id'],
                    'slots'    => $tpl['slots'],
                    'items'    => $res['assign'],
                    '_score'   => $score,
                ];
                \Log::debug('PlannerV2: best so far', [
                    'tpl' => $tpl['id'],
                    'score' => round($bestScore, 3),
                    'pen_hist' => $histPenalty,
                    'rep' => $rep,
                ]);
            } elseif ($score > $secondScore) {
                $secondScore = $score;
                $second = [
                    'template' => $tpl['id'],
                    'slots'    => $tpl['slots'],
                    'items'    => $res['assign'],
                    '_score'   => $score,
                ];
            }
        }

        if ($best) {
            // Soft random selection between best and a close second to add variety
            $topK = (int) ($var['top_k'] ?? 2);
            $randP = (float) ($var['pick_randomness'] ?? 0.25);
            $within = (float) ($var['second_within'] ?? 0.12);
            $chosen = $best;
            $alternative = $second;
            if ($topK >= 2 && $second && $best['_score'] > 0) {
                $close = (($best['_score'] - $second['_score']) / max(1e-6, abs($best['_score']))) <= $within;
                if ($close && mt_rand() / mt_getrandmax() < $randP) {
                    $chosen = $second; $alternative = $best;
                }
            }

            // Auto-retry if score is very low
            if ($alternative && ($chosen['_score'] ?? -INF) < -1.5 && ($alternative['_score'] ?? -INF) > ($chosen['_score'] ?? -INF)) {
                \Log::info('PlannerV2: low score, retry with second-best', [
                    'chosen' => $chosen['template'], 'score' => round($chosen['_score'],3),
                    'second' => $alternative['template'], 'second_score' => round($alternative['_score'],3)
                ]);
                $tmp = $chosen; $chosen = $alternative; $alternative = $tmp;
            }

            // Face crop validator: if any face would be cropped >25%, try alternative
            try {
                $featMap = [];
                if (config('photobook.ml.enable') && config('photobook.ml.faces') && $this->featRepo) {
                    $paths = array_map(fn($p)=>$p->path, $photosPage);
                    $featMap = $this->featRepo->getMany($paths);
                }
                $viol = $this->hasFaceCropViolation($chosen, $photosPage, $featMap);
                if ($viol && $alternative) {
                    $viol2 = $this->hasFaceCropViolation($alternative, $photosPage, $featMap);
                    if (!$viol2) {
                        \Log::info('PlannerV2: validator rejected candidate due to face crop; using alternative', [
                            'rejected' => $chosen['template'], 'alt' => $alternative['template']
                        ]);
                        $chosen = $alternative;
                    }
                }
            } catch (\Throwable $e) { /* ignore validator errors */ }

            \Log::info('PlannerV2: chosen', ['template' => $chosen['template'], 'score' => round($chosen['_score'],3)]);
            unset($chosen['_score']);
            return $chosen;
        }
        \Log::warning('PlannerV2: fallback to 1/full-bleed');
        return [
            'template'=>'1/full-bleed',
            'slots'=>LayoutTemplates::all()[1][0]['slots'],
            'items'=>[['photo'=>$photosPage[0],'slotIndex'=>0,'crop'=>'cover','objectPosition'=>'50% 50%']]
        ];
    }

    /** @param PhotoDto[] $photos */
    private function scoreTemplate(array $tpl, array $photos): array
    {
        $slots = $tpl['slots'];
        $n = count($photos);
        // Order slots top-left to bottom-right for chronology
        $slotOrder = [];
        foreach ($slots as $j => $s) {
            $slotOrder[$j] = $s['y'] + 0.6 * $s['x'];
        }
        asort($slotOrder); // low (top/left) to high (bottom/right)
        $slotRank = array_flip(array_keys($slotOrder)); // slot index -> rank 0..n-1

    // Read weights/bonuses from config
    $w = config('photobook.planner.weights');
    $b = config('photobook.planner.bonuses');
    $wCrop = (float) ($w['crop'] ?? 1.0);
    $wOrient = (float) ($w['orientation'] ?? 0.4);
    $wFlow = (float) ($w['chronology'] ?? 0.25);
    $heroBonus = (float) ($b['hero_bonus'] ?? 0.3);
    $heroMiss = (float) ($b['hero_miss_penalty'] ?? 0.05);
    $divPenalty = (float) ($b['diversity_penalty'] ?? 0.15);

    // Slot area fractions to weight hero-ness
    $areas = array_map(fn($s)=>$s['w']*$s['h'], $slots);
    $totalArea = array_sum($areas) ?: 1.0;
    $slotAreaFrac = array_map(fn($a)=> $a / max(1e-6, $totalArea), $areas);

    // Load features once (faces/aesthetic/sharpness etc.)
    $featMap = [];
    if (config('photobook.ml.enable') && $this->featRepo) {
        try {
            $paths = array_map(fn($p)=>$p->path, $photos);
            $featMap = $this->featRepo->getMany($paths);
        } catch (\Throwable $e) {
            $featMap = [];
        }
    }

    // Build cost matrix (photo i -> slot j)
        $cost = array_fill(0, $n, array_fill(0, $n, 0.0));
        for ($i = 0; $i < $n; $i++) {
            $p = $photos[$i];
            $par = $this->aspect($p);
            $pf = $featMap[$p->path] ?? null;
            // Precompute heroiness for this photo
            $heroinessBase = 0.0;
            if ($pf) {
                $faces = is_array($pf->faces ?? null) ? $pf->faces : [];
                if (!empty($faces)) { $heroinessBase += 0.5; }
                $aesthetic = $pf->aesthetic ?? null;
                if (is_numeric($aesthetic)) {
                    $heroinessBase += min(0.5, max(0.0, ((float)$aesthetic - 5.0) / 5.0));
                }
            }
            for ($j = 0; $j < $n; $j++) {
                $s = $slots[$j];
                $sar = $s['ar'] ?? $par;
                // Crop cost with quadratic-ish growth for extremes
                $cropCost = abs($sar - $par);
                $cropCost = $cropCost * (1.0 + 0.5 * $cropCost);
                // Orientation hard mismatch (portrait vs landscape)
                $mismatch = (($sar < 0.95 && $par > 1.2) || ($sar > 1.2 && $par < 0.95)) ? 1.0 : 0.0;
                // Chronology: earlier (lower i) to earlier rank slot
                $flow = abs($slotRank[$j] - $i) / max(1, $n - 1);
                // Hero-weighted bonus by slot area fraction
                $bonus = $heroinessBase * ($slotAreaFrac[$j] ?? 0.0) * $heroBonus;
                // Weighted sum (bonus reduces cost)
                $cost[$i][$j] = $wCrop * $cropCost + $wOrient * $mismatch + $wFlow * $flow - $bonus;
            }
        }

        // Solve assignment with Hungarian algorithm (minimization)
        $assignIdx = $this->hungarian($cost); // returns array [photoIndex => slotIndex]

        // Compute total cost
        $total = 0.0; $assign = [];
        foreach ($assignIdx as $i => $j) {
            $total += $cost[$i][$j];
            $assign[] = [
                'photo' => $photos[$i],
                'slotIndex' => $j,
                'crop' => 'cover',
                'objectPosition' => '50% 50%',
            ];
        }

        // Hero miss penalty: if largest slot didn't get a hero-worthy photo while a better remains
        $heroJ = array_keys($areas, max($areas))[0];
        $assignedIdxForHero = array_search($heroJ, $assignIdx, true);
        if ($assignedIdxForHero !== false) {
            $assignedPhoto = $photos[$assignedIdxForHero];
            $af = $featMap[$assignedPhoto->path] ?? null;
            $hscore = 0.0;
            if ($af) {
                $faces = is_array($af->faces ?? null) ? $af->faces : [];
                if (!empty($faces)) { $hscore += 0.5; }
                $aesthetic = $af->aesthetic ?? null;
                if (is_numeric($aesthetic)) {
                    $hscore += min(0.5, max(0.0, ((float)$aesthetic - 5.0) / 5.0));
                }
            }
            $bestRemainingHero = 0.0;
            // Compare against other photos on the page (exclude the one already in the largest slot)
            for ($k = 0; $k < $n; $k++) {
                if ($k === $assignedIdxForHero) continue;
                $pp = $photos[$k];
                $pf2 = $featMap[$pp->path] ?? null;
                if (!$pf2) continue;
                $tmp = 0.0;
                $faces2 = is_array($pf2->faces ?? null) ? $pf2->faces : [];
                if (!empty($faces2)) { $tmp += 0.5; }
                $aes2 = $pf2->aesthetic ?? null;
                if (is_numeric($aes2)) { $tmp += min(0.5, max(0.0, ((float)$aes2 - 5.0) / 5.0)); }
                if ($tmp > $bestRemainingHero) $bestRemainingHero = $tmp;
            }
            if ($bestRemainingHero > $hscore + 0.25) {
                $total += $heroMiss;
            }
        }

        // Diversity: penalize repetitive mismatches
        $mismatchCount = 0;
        foreach ($assign as $a) {
            $arS = $slots[$a['slotIndex']]['ar'] ?? 1.0;
            $arP = $this->aspect($a['photo']);
            if (($arS < 0.95 && $arP > 1.2) || ($arS > 1.2 && $arP < 0.95)) {
                $mismatchCount++;
            }
        }
        if ($mismatchCount >= max(2, (int) floor($n/2))) {
            $total += $divPenalty;
        }

    // PHP-only ML: prefer sharper image for largest slot if enabled (tiny)
    if (config('photobook.ml.enable') && config('photobook.ml.sharpness') && $this->featRepo) {
            try {
                $largestIdx = $heroJ;
                // Find which photo got that slot
                $photoIdxForLargest = array_search($largestIdx, $assignIdx, true);
                if ($photoIdxForLargest !== false) {
                    $sharp = 0.0;
                    $p = $photos[$photoIdxForLargest];
                    $f = $featMap[$p->path] ?? null;
                    if ($f && $f->sharpness) {
                        $sharp = log(1.0 + max(0.0, (float)$f->sharpness));
            $total -= min(0.2, $sharp/25.0); // tiny bonus
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Lower total => better; convert to score
        $score = -$total;
        \Log::debug('PlannerV2: scored template', [
            'tpl' => $tpl['id'] ?? 'unknown',
            'n' => $n,
            'total_cost' => round($total, 3),
            'score' => round($score, 3),
        ]);
        return ['score' => $score, 'assign' => $assign];
    }

    private function aspect(PhotoDto $p): float
    {
        if ($p->ratio) return $p->ratio;
        if ($p->width && $p->height && $p->height > 0) return $p->width / $p->height;
        // default “neutral” aspect
        return 1.0;
    }

    /**
     * Check if any assigned photo's primary face (if present with bbox) would be cropped >25%
     * given object-fit: cover with focal point set to that face center.
     * @param array{template:string,slots:array<int,array>,items:array<int,array>,_score?:float} $candidate
     * @param PhotoDto[] $photosPage
     * @param array<string,\App\Models\PhotoFeature> $featMap
     */
    private function hasFaceCropViolation(array $candidate, array $photosPage, array $featMap): bool
    {
        $slots = $candidate['slots'];
        foreach ($candidate['items'] as $it) {
            /** @var PhotoDto $p */
            $p = $it['photo'];
            $f = $featMap[$p->path] ?? null;
            if (!$f || !is_array($f->faces ?? null) || empty($f->faces)) continue;
            // take the largest face by (w*h) if dimensions exist
            $faces = $f->faces;
            usort($faces, function($A,$B){
                $a = (float)($A['w'] ?? 0.0) * (float)($A['h'] ?? 0.0);
                $b = (float)($B['w'] ?? 0.0) * (float)($B['h'] ?? 0.0);
                return $b <=> $a;
            });
            $face = $faces[0];
            $cx = isset($face['cx']) ? (float)$face['cx'] : 0.5;
            $cy = isset($face['cy']) ? (float)$face['cy'] : 0.5;
            $fw = isset($face['w']) ? (float)$face['w'] : null;
            $fh = isset($face['h']) ? (float)$face['h'] : null;
            if ($fw === null || $fh === null || $fw <= 0 || $fh <= 0) continue; // need bbox size to assess crop percent

            $slot = $slots[$it['slotIndex']] ?? null;
            if (!$slot) continue;
            $par = $this->aspect($p); if ($par <= 0) continue;
            $sar = (float)($slot['ar'] ?? $par); if ($sar <= 0) continue;

            // Compute visible rect in photo space (0..1) under object-fit: cover centered at (cx,cy)
            if ($par >= $sar) {
                // wider than slot: crop horizontally
                $visW = max(0.0, min(1.0, $sar / $par));
                $x0 = max(0.0, min(1.0 - $visW, $cx - $visW/2));
                $x1 = $x0 + $visW; $y0 = 0.0; $y1 = 1.0;
            } else {
                // taller than slot: crop vertically
                $visH = max(0.0, min(1.0, $par / $sar));
                $y0 = max(0.0, min(1.0 - $visH, $cy - $visH/2));
                $y1 = $y0 + $visH; $x0 = 0.0; $x1 = 1.0;
            }

            // Face bbox
            $fx0 = $cx - $fw/2; $fx1 = $cx + $fw/2;
            $fy0 = $cy - $fh/2; $fy1 = $cy + $fh/2;

            // Intersection area between visible rect and face bbox
            $ix = max(0.0, min($x1, $fx1) - max($x0, $fx0));
            $iy = max(0.0, min($y1, $fy1) - max($y0, $fy0));
            $interA = $ix * $iy;
            $faceA = max(1e-6, $fw * $fh);
            $coverage = $interA / $faceA; // 1.0 means fully inside
            if ($coverage < 0.75) {
                return true; // more than 25% cropped
            }
        }
        return false;
    }

    /** Hungarian algorithm for square cost matrix; returns assignment [row => col] minimizing total cost */
    private function hungarian(array $cost): array
    {
        $n = count($cost);
        // Copy matrix
        $a = $cost;
        // Row reduction
        for ($i=0;$i<$n;$i++) {
            $m = min($a[$i]);
            for ($j=0;$j<$n;$j++) $a[$i][$j]-=$m;
        }
        // Column reduction
        for ($j=0;$j<$n;$j++) {
            $m = INF; for ($i=0;$i<$n;$i++) $m = min($m, $a[$i][$j]);
            for ($i=0;$i<$n;$i++) $a[$i][$j]-=$m;
        }
        $u = array_fill(0,$n+1,0.0); $v = array_fill(0,$n+1,0.0);
        $p = array_fill(0,$n+1,0); $way = array_fill(0,$n+1,0);
        for ($i=1;$i<=$n;$i++) {
            $p[0] = $i; $j0 = 0;
            $minv = array_fill(0,$n+1, INF);
            $used = array_fill(0,$n+1,false);
            do {
                $used[$j0] = true; $i0 = $p[$j0]; $delta = INF; $j1 = 0;
                for ($j=1;$j<=$n;$j++) if (!$used[$j]) {
                    $cur = $a[$i0-1][$j-1]-$u[$i0]-$v[$j];
                    if ($cur < $minv[$j]) { $minv[$j] = $cur; $way[$j] = $j0; }
                    if ($minv[$j] < $delta) { $delta = $minv[$j]; $j1 = $j; }
                }
                for ($j=0;$j<=$n;$j++) {
                    if ($used[$j]) { $u[$p[$j]] += $delta; $v[$j] -= $delta; }
                    else { $minv[$j] -= $delta; }
                }
                $j0 = $j1;
            } while ($p[$j0] != 0);
            do { $j1 = $way[$j0]; $p[$j0] = $p[$j1]; $j0 = $j1; } while ($j0 != 0);
        }
        $ans = [];
        for ($j=1;$j<=$n;$j++) if ($p[$j] != 0) { $ans[$p[$j]-1] = $j-1; }
        ksort($ans);
        return $ans;
    }
}
