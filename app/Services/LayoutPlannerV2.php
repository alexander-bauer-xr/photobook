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
            if ($topK >= 2 && $second && $best['_score'] > 0) {
                $close = (($best['_score'] - $second['_score']) / max(1e-6, abs($best['_score']))) <= $within;
                if ($close && mt_rand() / mt_getrandmax() < $randP) {
                    $chosen = $second;
                }
            }
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

    // Build cost matrix (photo i -> slot j)
        $cost = array_fill(0, $n, array_fill(0, $n, 0.0));
        for ($i = 0; $i < $n; $i++) {
            $p = $photos[$i];
            $par = $this->aspect($p);
            for ($j = 0; $j < $n; $j++) {
                $s = $slots[$j];
                $sar = $s['ar'] ?? $par;
                $crop = abs($sar - $par); // lower is better
                $mismatch = (($sar < 0.95 && $par > 1.2) || ($sar > 1.2 && $par < 0.95)) ? 1.0 : 0.0;
                // Chronology: earlier (lower i) to earlier rank slot
                $flow = abs($slotRank[$j] - $i) / max(1, $n - 1);
                // Weighted sum
        $cost[$i][-$j-1] = 0; // placeholder to keep numeric keys if needed
        $cost[$i][$j] = $wCrop * $crop + $wOrient * $mismatch + $wFlow * $flow;
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

        // Hero bonus: if earliest photo (i=0) lands in the largest area slot, reward
        $areas = array_map(fn($s)=>$s['w']*$s['h'], $slots);
        $heroJ = array_keys($areas, max($areas))[0];
        if (isset($assignIdx[0]) && $assignIdx[0] === $heroJ) {
            $total -= $heroBonus; // bonus reduces cost
        } else {
            $total += $heroMiss; // slight penalty
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

        // PHP-only ML: prefer sharper image for largest slot if enabled
        if (config('photobook.ml.enable') && config('photobook.ml.sharpness') && $this->featRepo) {
            try {
                $largestIdx = $heroJ;
                // Find which photo got that slot
                $photoIdxForLargest = array_search($largestIdx, $assignIdx, true);
                if ($photoIdxForLargest !== false) {
                    $paths = array_map(fn($p)=>$p->path, $photos);
                    $featMap = $this->featRepo->getMany($paths);
                    $sharp = 0.0;
                    $p = $photos[$photoIdxForLargest];
                    $f = $featMap[$p->path] ?? null;
                    if ($f && $f->sharpness) {
                        $sharp = log(1.0 + max(0.0, (float)$f->sharpness));
                        $total -= min(0.3, $sharp/20.0); // small bonus
                    }
                    // Sidecar ML: if aesthetic score is available, add a tiny bonus too
                    if ($f && isset($f->aesthetic) && is_numeric($f->aesthetic)) {
                        $a = max(0.0, min(10.0, (float) $f->aesthetic));
                        // center around ~5; scale gently so it doesn't dominate
                        $delta = ($a - 5.0) / 50.0; // ~[-0.1, +0.1]
                        $total -= $delta;
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
