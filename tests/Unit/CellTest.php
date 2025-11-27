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

class CellTest extends TestCase
{
    #[Test]
    public function cells_with_same_content_are_equal(): void
    {
        $cell1 = new Cell('A', 1, 31, 41, null, null);
        $cell2 = new Cell('A', 1, 31, 41, null, null);

        $this->assertTrue($cell1->equals($cell2));
    }

    #[Test]
    public function cells_with_different_char_are_not_equal(): void
    {
        $cell1 = new Cell('A');
        $cell2 = new Cell('B');

        $this->assertFalse($cell1->equals($cell2));
    }

    #[Test]
    public function cells_with_different_style_are_not_equal(): void
    {
        $cell1 = new Cell('A', 1);
        $cell2 = new Cell('A', 2);

        $this->assertFalse($cell1->equals($cell2));
    }

    #[Test]
    public function cells_with_different_colors_are_not_equal(): void
    {
        $cell1 = new Cell('A', 0, 31);
        $cell2 = new Cell('A', 0, 32);

        $this->assertFalse($cell1->equals($cell2));
    }

    #[Test]
    public function blank_cell_is_space_with_no_style(): void
    {
        $cell = Cell::blank();

        $this->assertEquals(' ', $cell->char);
        $this->assertEquals(0, $cell->style);
        $this->assertNull($cell->fg);
        $this->assertNull($cell->bg);
        $this->assertFalse($cell->hasStyle());
    }

    #[Test]
    public function continuation_cell_is_empty(): void
    {
        $cell = Cell::continuation();

        $this->assertEquals('', $cell->char);
        $this->assertTrue($cell->isContinuation());
    }

    #[Test]
    public function regular_cell_is_not_continuation(): void
    {
        $cell = new Cell('A');

        $this->assertFalse($cell->isContinuation());
    }

    #[Test]
    public function cell_with_style_reports_has_style(): void
    {
        $cell = new Cell('A', 1);
        $this->assertTrue($cell->hasStyle());

        $cell = new Cell('A', 0, 31);
        $this->assertTrue($cell->hasStyle());

        $cell = new Cell('A', 0, null, 41);
        $this->assertTrue($cell->hasStyle());

        $cell = new Cell('A', 0, null, null, [2, 255, 0, 0]);
        $this->assertTrue($cell->hasStyle());
    }

    #[Test]
    public function with_char_creates_new_cell(): void
    {
        $cell1 = new Cell('A', 1, 31);
        $cell2 = $cell1->withChar('B');

        $this->assertEquals('A', $cell1->char);
        $this->assertEquals('B', $cell2->char);
        $this->assertEquals($cell1->style, $cell2->style);
        $this->assertEquals($cell1->fg, $cell2->fg);
    }

    #[Test]
    public function style_transition_empty_when_no_change(): void
    {
        $cell1 = new Cell('A', 1, 31);
        $cell2 = new Cell('B', 1, 31);

        $this->assertEquals('', $cell2->getStyleTransition($cell1));
    }

    #[Test]
    public function style_transition_adds_new_styles(): void
    {
        $cell1 = new Cell('A', 0);
        $cell2 = new Cell('B', 1); // Bold

        $transition = $cell2->getStyleTransition($cell1);
        $this->assertStringContainsString('1', $transition);
    }

    #[Test]
    public function style_transition_adds_foreground_color(): void
    {
        $cell1 = new Cell('A');
        $cell2 = new Cell('B', 0, 31); // Red foreground

        $transition = $cell2->getStyleTransition($cell1);
        $this->assertStringContainsString('31', $transition);
    }

    #[Test]
    public function style_transition_resets_when_style_removed(): void
    {
        $cell1 = new Cell('A', 1); // Bold
        $cell2 = new Cell('B', 0); // No bold

        $transition = $cell2->getStyleTransition($cell1);
        // Should contain reset code
        $this->assertStringContainsString("\e[0m", $transition);
    }

    #[Test]
    public function full_style_sequence_from_null(): void
    {
        $cell = new Cell('A', 1, 31); // Bold + red

        $sequence = $cell->getStyleTransition(null);
        $this->assertStringContainsString('1', $sequence);
        $this->assertStringContainsString('31', $sequence);
    }

    #[Test]
    public function extended_color_support(): void
    {
        $cell = new Cell('A', 0, null, null, [2, 255, 0, 0]); // RGB red foreground

        $sequence = $cell->getStyleTransition(null);
        $this->assertStringContainsString('38;2;255;0;0', $sequence);
    }

    #[Test]
    public function extended_background_color(): void
    {
        $cell = new Cell('A', 0, null, null, null, [5, 196]); // 256-color background

        $sequence = $cell->getStyleTransition(null);
        $this->assertStringContainsString('48;5;196', $sequence);
    }
}
