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
use SoloTerm\Screen\Buffers\CellBuffer;
use SoloTerm\Screen\Cell;
use SoloTerm\Screen\Screen;

class ResizeRoadmapTest extends TestCase
{
    #[Test]
    public function resize_can_expand_the_viewport_back_into_existing_scrollback(): void
    {
        $screen = new Screen(5, 3);

        $screen->write("1\n2\n3\n4\n5");

        $this->resizeScreen($screen, 5, 5);

        $buffer = $screen->toCellBuffer();

        $this->assertSame('1', $this->rowText($buffer, 0));
        $this->assertSame('2', $this->rowText($buffer, 1));
        $this->assertSame('3', $this->rowText($buffer, 2));
        $this->assertSame('4', $this->rowText($buffer, 3));
        $this->assertSame('5', $this->rowText($buffer, 4));
    }

    #[Test]
    public function resize_clamps_the_cursor_into_the_new_bounds(): void
    {
        $screen = new Screen(10, 5);

        $screen->write("\e[5;10HZ");

        $this->resizeScreen($screen, 4, 2);

        $this->assertLessThanOrEqual(1, $screen->cursorRow);
        $this->assertLessThanOrEqual(4, $screen->cursorCol);
    }

    #[Test]
    public function resize_updates_visible_width_without_requiring_a_rewrite(): void
    {
        $screen = new Screen(6, 2);

        $screen->write('abcdef');

        $this->resizeScreen($screen, 4, 2);

        $buffer = $screen->toCellBuffer();

        $this->assertSame(4, $buffer->getWidth());
        $this->assertSame('abcd', $this->rowText($buffer, 0));
    }

    #[Test]
    public function resize_can_shrink_then_regrow_without_losing_hidden_rows(): void
    {
        $screen = new Screen(5, 5);

        $screen->write("1\n2\n3\n4\n5");

        $this->resizeScreen($screen, 5, 2);
        $this->resizeScreen($screen, 5, 5);

        $buffer = $screen->toCellBuffer();

        $this->assertSame('1', $this->rowText($buffer, 0));
        $this->assertSame('2', $this->rowText($buffer, 1));
        $this->assertSame('3', $this->rowText($buffer, 2));
        $this->assertSame('4', $this->rowText($buffer, 3));
        $this->assertSame('5', $this->rowText($buffer, 4));
    }

    #[Test]
    public function resize_updates_the_main_screen_even_while_alternate_screen_is_active(): void
    {
        $screen = new Screen(6, 3);

        $screen->write("mainxx\nmainyy\e[?1049haltzz");

        $this->resizeScreen($screen, 4, 3);
        $screen->write("\e[?1049l");

        $buffer = $screen->toCellBuffer();

        $this->assertSame(4, $screen->width);
        $this->assertSame('main', $this->rowText($buffer, 0));
        $this->assertSame('main', $this->rowText($buffer, 1));
    }

    #[Test]
    public function differential_output_clears_rows_that_disappear_after_height_shrink(): void
    {
        $screen = new Screen(6, 3);

        $screen->write("line1\nline2\nline3");
        $screen->output();
        $seqNo = $screen->getLastRenderedSeqNo();

        $this->resizeScreen($screen, 6, 2);

        $diffOutput = $screen->output($seqNo);

        $this->assertStringContainsString("\033[3;1H\033[K", $diffOutput);
    }

    #[Test]
    public function resize_clips_wide_characters_that_no_longer_fit_on_the_row(): void
    {
        $screen = new Screen(5, 2);

        $screen->write('ab文');

        $this->resizeScreen($screen, 3, 2);

        $buffer = $screen->toCellBuffer();

        $this->assertSame('ab', $this->rowText($buffer, 0));
        $this->assertStringNotContainsString('文', $screen->output());
    }

    private function resizeScreen(Screen $screen, int $width, int $height): void
    {
        $this->assertTrue(
            is_callable([$screen, 'resize']),
            'Screen needs a public resize(int $width, int $height) API.'
        );

        if (!is_callable([$screen, 'resize'])) {
            return;
        }

        $screen->resize($width, $height);
    }

    private function rowText(CellBuffer $buffer, int $row): string
    {
        return rtrim(implode('', array_map(
            static fn (Cell $cell) => $cell->char,
            $buffer->getRow($row)
        )));
    }
}
