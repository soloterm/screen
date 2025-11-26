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

class CellBufferTest extends TestCase
{
    #[Test]
    public function buffer_initializes_with_correct_dimensions(): void
    {
        $buffer = new CellBuffer(80, 24);

        $this->assertEquals(80, $buffer->getWidth());
        $this->assertEquals(24, $buffer->getHeight());
    }

    #[Test]
    public function buffer_initializes_with_blank_cells(): void
    {
        $buffer = new CellBuffer(10, 5);

        $cell = $buffer->getCell(0, 0);
        $this->assertEquals(' ', $cell->char);
        $this->assertFalse($cell->hasStyle());
    }

    #[Test]
    public function write_char_stores_character(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');

        $cell = $buffer->getCell(0, 0);
        $this->assertEquals('A', $cell->char);
    }

    #[Test]
    public function write_char_applies_current_style(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->setStyle(1, 31, null, null, null); // Bold + red
        $buffer->writeChar(0, 0, 'A');

        $cell = $buffer->getCell(0, 0);
        $this->assertEquals('A', $cell->char);
        $this->assertEquals(1, $cell->style);
        $this->assertEquals(31, $cell->fg);
    }

    #[Test]
    public function clear_region_resets_cells(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');
        $buffer->writeChar(0, 1, 'B');

        $buffer->clear(0, 0, 0, 1);

        $this->assertEquals(' ', $buffer->getCell(0, 0)->char);
        $this->assertEquals(' ', $buffer->getCell(0, 1)->char);
    }

    #[Test]
    public function clear_line_resets_entire_row(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');
        $buffer->writeChar(0, 5, 'B');

        $buffer->clearLine(0);

        $this->assertEquals(' ', $buffer->getCell(0, 0)->char);
        $this->assertEquals(' ', $buffer->getCell(0, 5)->char);
    }

    #[Test]
    public function fill_writes_character_with_style(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->setStyle(0, 32, null, null, null); // Green
        $buffer->fill('X', 0, 0, 4);

        for ($col = 0; $col <= 4; $col++) {
            $cell = $buffer->getCell(0, $col);
            $this->assertEquals('X', $cell->char);
            $this->assertEquals(32, $cell->fg);
        }
    }

    #[Test]
    public function ensure_row_expands_buffer(): void
    {
        $buffer = new CellBuffer(10, 5);
        $this->assertEquals(5, $buffer->getHeight());

        $buffer->ensureRow(10);
        $this->assertEquals(11, $buffer->getHeight());
    }

    #[Test]
    public function get_row_returns_cells(): void
    {
        $buffer = new CellBuffer(5, 3);
        $buffer->writeChar(0, 0, 'H');
        $buffer->writeChar(0, 1, 'i');

        $row = $buffer->getRow(0);
        $this->assertCount(5, $row);
        $this->assertEquals('H', $row[0]->char);
        $this->assertEquals('i', $row[1]->char);
    }

    #[Test]
    public function render_row_produces_string(): void
    {
        $buffer = new CellBuffer(5, 3);
        $buffer->writeChar(0, 0, 'H');
        $buffer->writeChar(0, 1, 'i');

        $output = $buffer->renderRow(0);
        $this->assertStringContainsString('Hi', $output);
    }

    #[Test]
    public function render_row_includes_ansi_codes(): void
    {
        $buffer = new CellBuffer(10, 3);
        $buffer->setStyle(1, 31, null, null, null); // Bold + red
        $buffer->writeChar(0, 0, 'R');
        $buffer->resetStyle();
        $buffer->writeChar(0, 1, 'N');

        $output = $buffer->renderRow(0);
        // Should contain color code for red
        $this->assertStringContainsString("\e[", $output);
        $this->assertStringContainsString('31', $output);
    }

    #[Test]
    public function insert_lines_shifts_content(): void
    {
        $buffer = new CellBuffer(5, 3);
        $buffer->writeChar(0, 0, 'A');
        $buffer->writeChar(1, 0, 'B');

        $buffer->insertLines(0, 1);

        $this->assertEquals(' ', $buffer->getCell(0, 0)->char);
        $this->assertEquals('A', $buffer->getCell(1, 0)->char);
        $this->assertEquals('B', $buffer->getCell(2, 0)->char);
    }

    #[Test]
    public function delete_lines_removes_content(): void
    {
        $buffer = new CellBuffer(5, 5);
        $buffer->writeChar(0, 0, 'A');
        $buffer->writeChar(1, 0, 'B');
        $buffer->writeChar(2, 0, 'C');

        $buffer->deleteLines(0, 1);

        $this->assertEquals('B', $buffer->getCell(0, 0)->char);
        $this->assertEquals('C', $buffer->getCell(1, 0)->char);
    }

    #[Test]
    public function sequence_number_tracking(): void
    {
        $seqNo = 0;
        $buffer = new CellBuffer(10, 5);
        $buffer->setSeqNoProvider(function () use (&$seqNo) {
            return ++$seqNo;
        });

        $buffer->writeChar(0, 0, 'A');
        $changedRows = $buffer->getChangedRows(0);

        $this->assertContains(0, $changedRows);
    }

    #[Test]
    public function continuation_cell_for_wide_characters(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, '文'); // Wide character
        $buffer->writeContinuation(0, 1);

        $this->assertEquals('文', $buffer->getCell(0, 0)->char);
        $this->assertTrue($buffer->getCell(0, 1)->isContinuation());
    }

    #[Test]
    public function render_skips_continuation_cells(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, '文');
        $buffer->writeContinuation(0, 1);
        $buffer->writeChar(0, 2, 'A');

        $output = $buffer->renderRow(0);
        // Should contain the wide char and A, but continuation doesn't add extra
        $this->assertStringContainsString('文', $output);
        $this->assertStringContainsString('A', $output);
    }

    #[Test]
    public function benchmark_flat_vs_2d_access(): void
    {
        $buffer = new CellBuffer(200, 50);

        // Time writes
        $start = hrtime(true);
        for ($i = 0; $i < 100; $i++) {
            for ($row = 0; $row < 50; $row++) {
                for ($col = 0; $col < 200; $col++) {
                    $buffer->writeChar($row, $col, 'X');
                }
            }
        }
        $writeTime = hrtime(true) - $start;

        // Time reads
        $start = hrtime(true);
        for ($i = 0; $i < 100; $i++) {
            for ($row = 0; $row < 50; $row++) {
                for ($col = 0; $col < 200; $col++) {
                    $cell = $buffer->getCell($row, $col);
                }
            }
        }
        $readTime = hrtime(true) - $start;

        // Just ensure it completes in reasonable time (< 5 seconds)
        $this->assertLessThan(5_000_000_000, $writeTime);
        $this->assertLessThan(5_000_000_000, $readTime);
    }
}
