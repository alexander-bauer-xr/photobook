<?php

namespace Tests\Unit;

use App\Services\HungarianSolver;
use PHPUnit\Framework\TestCase;

class HungarianSolverTest extends TestCase
{
    private HungarianSolver $solver;

    protected function setUp(): void
    {
        $this->solver = new HungarianSolver();
    }

    public function test_empty_matrix_returns_empty(): void
    {
        $this->assertSame([], $this->solver->solve([]));
    }

    public function test_single_element(): void
    {
        $result = $this->solver->solve([[5.0]]);
        $this->assertSame([0 => 0], $result);
    }

    public function test_identity_assignment_2x2(): void
    {
        // Clear diagonal wins
        $cost   = [[0.0, 10.0], [10.0, 0.0]];
        $result = $this->solver->solve($cost);

        $this->assertSame(0, $result[0]);
        $this->assertSame(1, $result[1]);
    }

    public function test_crossed_assignment_2x2(): void
    {
        // Off-diagonal wins
        $cost   = [[10.0, 1.0], [1.0, 10.0]];
        $result = $this->solver->solve($cost);

        $this->assertSame(1, $result[0]);
        $this->assertSame(0, $result[1]);
    }

    public function test_3x3_known_solution(): void
    {
        // Optimal: 0→2 (cost 1), 1→0 (cost 2), 2→1 (cost 3) = total 6
        $cost = [
            [9.0, 2.0, 1.0],
            [2.0, 8.0, 3.0],
            [3.0, 3.0, 6.0],
        ];
        $result = $this->solver->solve($cost);

        $total = $cost[$result[0] - ($result[0] - $result[0])][$result[0]] ?? 0;
        // Verify it's a valid bijection
        $slots = array_values($result);
        sort($slots);
        $this->assertSame([0, 1, 2], $slots);

        // Verify optimal total cost
        $totalCost = array_sum(array_map(fn($p, $s) => $cost[$p][$s], array_keys($result), $result));
        $this->assertEqualsWithDelta(6.0, $totalCost, 1e-9);
    }

    public function test_result_is_bijection_for_4x4(): void
    {
        srand(42);
        $n    = 4;
        $cost = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $cost[$i][$j] = (float) rand(1, 100);
            }
        }

        $result = $this->solver->solve($cost);

        // All photos assigned
        $this->assertCount($n, $result);
        // All slots unique
        $this->assertCount($n, array_unique(array_values($result)));
        // All indices in range
        foreach ($result as $photo => $slot) {
            $this->assertGreaterThanOrEqual(0, $slot);
            $this->assertLessThan($n, $slot);
        }
    }
}
