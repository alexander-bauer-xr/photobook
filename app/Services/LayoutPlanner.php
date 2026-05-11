<?php

namespace App\Services;

use App\DTO\PhotoDto;

/**
 * Copilot prompt:
 * Produce a simple page plan:
 * - plan(array $photos, array $options = []): array
 * - Each page: ['template' => '1up|2up|3up', 'photos' => PhotoDto[]]
 * - Greedy: take 3 squares as 3up; else 2 same-orientation as 2up; else 1up
 */
class LayoutPlanner
{
    /** @param PhotoDto[] $photos */
    public function plan(array $photos, array $options = []): array
    {
        $pages = [];
        $queue = array_values($photos);
        $preferTwoUp = (bool)($options['prefer_2up'] ?? true);
        $enableThreeUpAny = (bool)($options['three_up_any'] ?? false);

        while ($queue) {
            $a = array_shift($queue);
            if (count($queue) >= 2 && ($a->isSquare() && $queue[0]->isSquare() && $queue[1]->isSquare() || $enableThreeUpAny)) {
                $b = array_shift($queue);
                $c = array_shift($queue);
                $pages[] = ['template' => '3up', 'photos' => [$a,$b,$c]];
                continue;
            }
            if (count($queue) >= 1) {
                $b = $queue[0];
                $aHas = $a->ratio !== null; $bHas = $b->ratio !== null;
                // If dimensions unknown, default to 2-up when preferred
                if ($preferTwoUp && (!$aHas || !$bHas)) {
                    array_shift($queue);
                    $pages[] = ['template' => '2up', 'photos' => [$a,$b]];
                    continue;
                }
                if (($a->isLandscape() && $b->isLandscape()) || ($a->isPortrait() && $b->isPortrait())) {
                    array_shift($queue);
                    $pages[] = ['template' => '2up', 'photos' => [$a,$b]];
                    continue;
                }
            }
            $pages[] = ['template' => '1up', 'photos' => [$a]];
        }

        return $pages;
    }
}