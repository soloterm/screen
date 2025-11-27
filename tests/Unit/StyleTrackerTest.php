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
use SoloTerm\Screen\Cell;
use SoloTerm\Screen\Output\StyleTracker;

class StyleTrackerTest extends TestCase
{
    #[Test]
    public function starts_with_no_style(): void
    {
        $tracker = new StyleTracker();

        $this->assertFalse($tracker->hasStyle());
    }

    #[Test]
    public function no_transition_for_identical_style(): void
    {
        $tracker = new StyleTracker();
        $cell = Cell::blank();

        $result = $tracker->transitionTo($cell);

        $this->assertEquals('', $result);
    }

    #[Test]
    public function adds_foreground_color(): void
    {
        $tracker = new StyleTracker();
        $cell = new Cell('A', 0, 31); // Red foreground

        $result = $tracker->transitionTo($cell);

        $this->assertEquals("\e[31m", $result);
        $this->assertTrue($tracker->hasStyle());
    }

    #[Test]
    public function adds_background_color(): void
    {
        $tracker = new StyleTracker();
        $cell = new Cell('A', 0, null, 44); // Blue background

        $result = $tracker->transitionTo($cell);

        $this->assertEquals("\e[44m", $result);
    }

    #[Test]
    public function adds_bold_style(): void
    {
        $tracker = new StyleTracker();
        $cell = new Cell('A', 1); // Bold (bit 0)

        $result = $tracker->transitionTo($cell);

        $this->assertEquals("\e[1m", $result);
    }

    #[Test]
    public function adds_multiple_styles(): void
    {
        $tracker = new StyleTracker();
        $cell = new Cell('A', 3, 31); // Bold + Dim + Red

        $result = $tracker->transitionTo($cell);

        $this->assertStringContainsString('1', $result); // Bold
        $this->assertStringContainsString('2', $result); // Dim
        $this->assertStringContainsString('31', $result); // Red
    }

    #[Test]
    public function incremental_add_doesnt_repeat(): void
    {
        $tracker = new StyleTracker();

        // First: add red
        $cell1 = new Cell('A', 0, 31);
        $tracker->transitionTo($cell1);

        // Second: add bold, keep red
        $cell2 = new Cell('A', 1, 31);
        $result = $tracker->transitionTo($cell2);

        // Should only add bold, not red again
        $this->assertEquals("\e[1m", $result);
    }

    #[Test]
    public function removing_style_triggers_reset(): void
    {
        $tracker = new StyleTracker();

        // First: bold + red
        $cell1 = new Cell('A', 1, 31);
        $tracker->transitionTo($cell1);

        // Second: just red (removing bold)
        $cell2 = new Cell('A', 0, 31);
        $result = $tracker->transitionTo($cell2);

        // Should contain reset
        $this->assertStringContainsString('0', $result);
        // Should re-add red
        $this->assertStringContainsString('31', $result);
    }

    #[Test]
    public function reset_clears_state(): void
    {
        $tracker = new StyleTracker();

        $cell = new Cell('A', 1, 31);
        $tracker->transitionTo($cell);

        $this->assertTrue($tracker->hasStyle());

        $tracker->reset();

        $this->assertFalse($tracker->hasStyle());
    }

    #[Test]
    public function reset_if_needed_emits_code(): void
    {
        $tracker = new StyleTracker();

        $cell = new Cell('A', 1, 31);
        $tracker->transitionTo($cell);

        $result = $tracker->resetIfNeeded();

        $this->assertEquals("\e[0m", $result);
    }

    #[Test]
    public function reset_if_needed_empty_when_no_style(): void
    {
        $tracker = new StyleTracker();

        $result = $tracker->resetIfNeeded();

        $this->assertEquals('', $result);
    }

    #[Test]
    public function handles_extended_256_color_foreground(): void
    {
        $tracker = new StyleTracker();
        $cell = new Cell('A', 0, null, null, [5, 196]); // 256-color red

        $result = $tracker->transitionTo($cell);

        $this->assertEquals("\e[38;5;196m", $result);
    }

    #[Test]
    public function handles_extended_rgb_foreground(): void
    {
        $tracker = new StyleTracker();
        $cell = new Cell('A', 0, null, null, [2, 255, 128, 0]); // RGB orange

        $result = $tracker->transitionTo($cell);

        $this->assertEquals("\e[38;2;255;128;0m", $result);
    }

    #[Test]
    public function handles_extended_background(): void
    {
        $tracker = new StyleTracker();
        $cell = new Cell('A', 0, null, null, null, [5, 21]); // 256-color blue bg

        $result = $tracker->transitionTo($cell);

        $this->assertEquals("\e[48;5;21m", $result);
    }

    #[Test]
    public function transition_from_extended_to_basic(): void
    {
        $tracker = new StyleTracker();

        // First: 256-color
        $cell1 = new Cell('A', 0, null, null, [5, 196]);
        $tracker->transitionTo($cell1);

        // Second: basic color
        $cell2 = new Cell('A', 0, 31);
        $result = $tracker->transitionTo($cell2);

        // Should reset and apply basic color
        $this->assertStringContainsString('0', $result);
        $this->assertStringContainsString('31', $result);
    }

    #[Test]
    public function benchmark_style_tracking(): void
    {
        $tracker = new StyleTracker();

        // Simulate realistic terminal output: runs of same style with occasional changes
        // Like: "ERROR: " in red, then "some message" in default, repeated
        $cells = [];
        for ($i = 0; $i < 20; $i++) {
            // 5 red bold chars
            for ($j = 0; $j < 5; $j++) {
                $cells[] = new Cell('A', 1, 31);
            }
            // 15 normal chars
            for ($j = 0; $j < 15; $j++) {
                $cells[] = new Cell('A', 0, null);
            }
        }

        $trackedBytes = 0;
        $fullBytes = 0;

        foreach ($cells as $cell) {
            $trackedBytes += strlen($tracker->transitionTo($cell));
            // Full would always emit complete sequence
            $codes = [];
            if ($cell->style & 1) $codes[] = '1';
            if ($cell->fg) $codes[] = (string) $cell->fg;
            if (!empty($codes)) {
                $fullBytes += strlen("\e[" . implode(';', $codes) . "m");
            }
        }

        echo "\n\nStyle Tracking Optimization (realistic runs):\n";
        echo "  Tracked: {$trackedBytes} bytes\n";
        echo "  Full Each Time: {$fullBytes} bytes\n";
        echo "  Savings: " . round((1 - $trackedBytes / $fullBytes) * 100, 1) . "%\n";

        // Tracked should be smaller (due to incremental updates)
        $this->assertLessThan($fullBytes, $trackedBytes);
    }
}
