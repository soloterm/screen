<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Screen\Output\CursorOptimizer;

class CursorOptimizerTest extends TestCase
{
    #[Test]
    public function starts_at_origin(): void
    {
        $optimizer = new CursorOptimizer;

        $this->assertEquals(['row' => 0, 'col' => 0], $optimizer->getPosition());
    }

    #[Test]
    public function no_movement_when_already_at_target(): void
    {
        $optimizer = new CursorOptimizer;

        $result = $optimizer->moveTo(0, 0);

        $this->assertEquals('', $result);
    }

    #[Test]
    public function uses_home_for_origin(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(10, 10);

        $result = $optimizer->moveTo(0, 0);

        $this->assertEquals("\e[H", $result);
    }

    #[Test]
    public function uses_carriage_return_for_column_zero(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(5, 50);

        $result = $optimizer->moveTo(5, 0);

        $this->assertEquals("\r", $result);
    }

    #[Test]
    public function uses_newline_for_down_one_from_col_zero(): void
    {
        $optimizer = new CursorOptimizer;
        // Start at row 5, col 0
        $optimizer->moveTo(5, 0);

        $result = $optimizer->moveTo(6, 0);

        $this->assertEquals("\n", $result);
    }

    #[Test]
    public function tracks_position_after_movement(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(10, 20);

        $this->assertEquals(['row' => 10, 'col' => 20], $optimizer->getPosition());
    }

    #[Test]
    public function advance_updates_column(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(5, 10);
        $optimizer->advance(1);

        $this->assertEquals(['row' => 5, 'col' => 11], $optimizer->getPosition());
    }

    #[Test]
    public function advance_handles_wide_characters(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(5, 10);
        $optimizer->advance(2);

        $this->assertEquals(['row' => 5, 'col' => 12], $optimizer->getPosition());
    }

    #[Test]
    public function reset_returns_to_origin(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(10, 20);
        $optimizer->reset();

        $this->assertEquals(['row' => 0, 'col' => 0], $optimizer->getPosition());
    }

    #[Test]
    public function relative_move_right_one(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(5, 10);

        $result = $optimizer->moveTo(5, 11);

        $this->assertEquals("\e[C", $result);
    }

    #[Test]
    public function relative_move_left_one(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(5, 10);

        $result = $optimizer->moveTo(5, 9);

        $this->assertEquals("\e[D", $result);
    }

    #[Test]
    public function relative_move_up_one(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(5, 10);

        $result = $optimizer->moveTo(4, 10);

        $this->assertEquals("\e[A", $result);
    }

    #[Test]
    public function relative_move_down_one(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(5, 10);

        $result = $optimizer->moveTo(6, 10);

        $this->assertEquals("\e[B", $result);
    }

    #[Test]
    public function relative_move_multiple_right(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(5, 10);

        $result = $optimizer->moveTo(5, 15);

        $this->assertEquals("\e[5C", $result);
    }

    #[Test]
    public function absolute_move_when_cheaper(): void
    {
        $optimizer = new CursorOptimizer;

        // From origin to row 1, col 1: absolute is ESC[2;2H (6 bytes)
        // Relative would be ESC[B + ESC[C (6 bytes) - equal, might choose either
        $result = $optimizer->moveTo(1, 1);

        // Should produce valid positioning
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function chooses_optimal_for_large_jump(): void
    {
        $optimizer = new CursorOptimizer;
        $optimizer->moveTo(0, 0);

        // Jump to row 25, col 80
        // Absolute: ESC[26;81H = 9 bytes
        // Relative: ESC[25B + ESC[80C = 10 bytes
        $result = $optimizer->moveTo(25, 80);

        // Should use absolute positioning
        $this->assertEquals("\e[26;81H", $result);
    }
}
