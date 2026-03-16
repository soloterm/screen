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
use SoloTerm\Screen\Screen;

class RenderStateRoadmapTest extends TestCase
{
    #[Test]
    public function decrc_restores_active_basic_sgr_state_not_just_cursor_position(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("\e[1;31;43mA\e7\e[0mB\e8C");

        $buffer = $screen->toCellBuffer();
        $first = $buffer->getCell(0, 0);
        $restored = $buffer->getCell(0, 1);

        $this->assertSame('A', $first->char);
        $this->assertSame('C', $restored->char);
        $this->assertSame($first->style, $restored->style, 'DECRC should restore active decoration state.');
        $this->assertSame($first->fg, $restored->fg, 'DECRC should restore active foreground color.');
        $this->assertSame($first->bg, $restored->bg, 'DECRC should restore active background color.');
    }

    #[Test]
    public function decrc_restores_active_extended_foreground_color(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("\e[38;5;196mA\e7\e[38;5;34mB\e8C");

        $buffer = $screen->toCellBuffer();
        $first = $buffer->getCell(0, 0);
        $restored = $buffer->getCell(0, 1);

        $this->assertSame('A', $first->char);
        $this->assertSame('C', $restored->char);
        $this->assertSame($first->extFg, $restored->extFg, 'DECRC should restore extended foreground color state.');
    }

    #[Test]
    public function decrc_restores_charset_selection(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("\e(0l\e7\e(Bq\e8q");

        $this->assertSame('┌─', $screen->printable->lines()[0]);
    }
}
