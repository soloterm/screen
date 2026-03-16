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

class CompatibilityRoadmapTest extends TestCase
{
    #[Test]
    public function split_sgr_sequence_across_writes_is_buffered_until_complete(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("Hello \e[31");
        $screen->write('mRed');

        $this->assertSame('Hello Red', $screen->printable->lines()[0]);
        $this->assertStringContainsString("\e[31mRed", $screen->output());
    }

    #[Test]
    public function split_cursor_position_sequence_across_writes_moves_cursor_when_completed(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("abc\nxyz\e[2;");
        $screen->write('1H@');

        $this->assertSame('abc', $screen->printable->lines()[0]);
        $this->assertSame('@yz', $screen->printable->lines()[1]);
        $this->assertSame(1, $screen->cursorRow);
        $this->assertSame(1, $screen->cursorCol);
    }

    #[Test]
    public function delete_character_sequence_shifts_remaining_text_left(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("abcdef\e[3G\e[2P");

        $this->assertSame('abef', $screen->printable->lines()[0]);
    }

    #[Test]
    public function insert_character_sequence_makes_room_for_new_text(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("abef\e[3G\e[2@cd");

        $this->assertSame('abcdef', $screen->printable->lines()[0]);
    }

    #[Test]
    public function erase_character_sequence_replaces_cells_with_spaces_without_shifting_text(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("abcdef\e[3G\e[2X");

        $this->assertSame('ab  ef', $screen->printable->lines()[0]);
    }

    #[Test]
    public function reverse_index_at_top_scrolls_content_down(): void
    {
        $screen = new Screen(5, 3);

        $screen->write("1\n2\n3\e[H\eM@");

        $this->assertSame('@', $screen->printable->lines()[0]);
        $this->assertSame('1', $screen->printable->lines()[1]);
        $this->assertSame('2', $screen->printable->lines()[2]);
    }

    #[Test]
    public function insert_character_uses_only_the_active_background_for_new_blank_cells(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("\e[1;31;43mab\e[1G\e[2@");

        $row = $screen->ansi->buffer[0];
        $backgroundOnly = $this->backgroundOnlyAnsiCell();
        $fullyStyled = $this->fullyStyledAnsiCell();

        $this->assertSame($backgroundOnly, $row[0] ?? null);
        $this->assertSame($backgroundOnly, $row[1] ?? null);
        $this->assertSame($fullyStyled, $row[2] ?? null);
        $this->assertSame($fullyStyled, $row[3] ?? null);
    }

    #[Test]
    public function erase_character_uses_only_the_active_background_for_erased_cells(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("\e[1;31;43mabcd\e[1G\e[2X");

        $row = $screen->ansi->buffer[0];
        $backgroundOnly = $this->backgroundOnlyAnsiCell();
        $fullyStyled = $this->fullyStyledAnsiCell();

        $this->assertSame($backgroundOnly, $row[0] ?? null);
        $this->assertSame($backgroundOnly, $row[1] ?? null);
        $this->assertSame($fullyStyled, $row[2] ?? null);
        $this->assertSame($fullyStyled, $row[3] ?? null);
    }

    #[Test]
    public function delete_character_retains_active_background_in_newly_empty_cells(): void
    {
        $screen = new Screen(20, 5);

        $screen->write("\e[43mabcd\e[1G\e[2P");

        $row = $screen->ansi->buffer[0];
        $backgroundOnly = $this->backgroundOnlyAnsiCell();

        $this->assertSame($backgroundOnly, $row[0] ?? null);
        $this->assertSame($backgroundOnly, $row[1] ?? null);
        $this->assertSame($backgroundOnly, $row[18] ?? null);
        $this->assertSame($backgroundOnly, $row[19] ?? null);
        $this->assertSame(' ', $screen->printable->buffer[0][18] ?? null);
        $this->assertSame(' ', $screen->printable->buffer[0][19] ?? null);
    }

    private function backgroundOnlyAnsiCell(): int|array
    {
        $screen = new Screen(5, 1);
        $screen->write("\e[43m ");

        return $screen->ansi->buffer[0][0];
    }

    private function fullyStyledAnsiCell(): int|array
    {
        $screen = new Screen(5, 1);
        $screen->write("\e[1;31;43m ");

        return $screen->ansi->buffer[0][0];
    }
}
