<?php

namespace App\Services;

class HungarianSolver
{
    /**
     * Solve the linear assignment problem for a square cost matrix.
     *
     * Uses Jonker-Volgenant / Successive Shortest Paths (O(n³)).
     *
     * @param  float[][] $cost n×n cost matrix, $cost[$photo][$slot]
     * @return int[] Map of photoIndex → slotIndex, minimising total cost
     */
    public function solve(array $cost): array
    {
        $n = count($cost);
        if ($n === 0) {
            return [];
        }

        $u   = array_fill(0, $n + 1, 0.0);
        $v   = array_fill(0, $n + 1, 0.0);
        $p   = array_fill(0, $n + 1, 0);
        $way = array_fill(0, $n + 1, 0);

        for ($i = 1; $i <= $n; $i++) {
            $p[0] = $i;
            $j0   = 0;
            $minv = array_fill(0, $n + 1, INF);
            $used = array_fill(0, $n + 1, false);

            do {
                $used[$j0] = true;
                $i0    = $p[$j0];
                $delta = INF;
                $j1    = 0;

                for ($j = 1; $j <= $n; $j++) {
                    if (!$used[$j]) {
                        $cur = $cost[$i0 - 1][$j - 1] - $u[$i0] - $v[$j];
                        if ($cur < $minv[$j]) {
                            $minv[$j] = $cur;
                            $way[$j]  = $j0;
                        }
                        if ($minv[$j] < $delta) {
                            $delta = $minv[$j];
                            $j1    = $j;
                        }
                    }
                }

                for ($j = 0; $j <= $n; $j++) {
                    if ($used[$j]) {
                        $u[$p[$j]] += $delta;
                        $v[$j]     -= $delta;
                    } else {
                        $minv[$j] -= $delta;
                    }
                }

                $j0 = $j1;
            } while ($p[$j0] !== 0);

            do {
                $j1      = $way[$j0];
                $p[$j0]  = $p[$j1];
                $j0      = $j1;
            } while ($j0 !== 0);
        }

        $assignment = [];
        for ($j = 1; $j <= $n; $j++) {
            if ($p[$j] !== 0) {
                $assignment[$p[$j] - 1] = $j - 1;
            }
        }
        ksort($assignment);

        return $assignment;
    }
}
