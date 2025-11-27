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
use SoloTerm\Screen\Buffers\CellBuffer;

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
    public function insert_lines_shifts_sequence_numbers(): void
    {
        $seqNo = 0;
        $buffer = new CellBuffer(10, 5);
        $buffer->setSeqNoProvider(function () use (&$seqNo) {
            return ++$seqNo;
        });

        // Write to rows 0, 1, 2 (seqNos 1, 2, 3)
        $buffer->writeChar(0, 0, 'A');
        $buffer->writeChar(1, 0, 'B');
        $buffer->writeChar(2, 0, 'C');

        // Get the sequence number after writing to row 1
        $seqAfterRow1 = 2;

        // Insert 2 lines at row 1 - should shift rows 1,2 to become rows 3,4
        $buffer->insertLines(1, 2);

        // Row 0 should still have A
        $this->assertEquals('A', $buffer->getCell(0, 0)->char);
        // Rows 1,2 are new blank rows
        $this->assertEquals(' ', $buffer->getCell(1, 0)->char);
        $this->assertEquals(' ', $buffer->getCell(2, 0)->char);
        // Old row 1 (B) is now row 3
        $this->assertEquals('B', $buffer->getCell(3, 0)->char);
        // Old row 2 (C) is now row 4
        $this->assertEquals('C', $buffer->getCell(4, 0)->char);

        // The sequence numbers should have shifted - row 3 should have the
        // sequence number that was originally assigned to row 1
        $changedRows = $buffer->getChangedRows($seqAfterRow1);
        // Row 3 (originally row 1) was modified at seqNo 2, so it should appear
        // in changes since seqNo 2
        $this->assertContains(3, $changedRows);
    }

    #[Test]
    public function delete_lines_shifts_sequence_numbers(): void
    {
        $seqNo = 0;
        $buffer = new CellBuffer(10, 5);
        $buffer->setSeqNoProvider(function () use (&$seqNo) {
            return ++$seqNo;
        });

        // Write to rows 0, 1, 2, 3 (seqNos 1, 2, 3, 4)
        $buffer->writeChar(0, 0, 'A');
        $buffer->writeChar(1, 0, 'B');
        $buffer->writeChar(2, 0, 'C');
        $buffer->writeChar(3, 0, 'D');

        // Get the sequence number after writing to row 2
        $seqAfterRow2 = 3;

        // Delete rows 1 and 2 - rows 3 becomes row 1
        $buffer->deleteLines(1, 2);

        // Row 0 should still have A
        $this->assertEquals('A', $buffer->getCell(0, 0)->char);
        // Old row 3 (D) is now row 1
        $this->assertEquals('D', $buffer->getCell(1, 0)->char);

        // The sequence number for old row 3 should now be at row 1
        $changedRows = $buffer->getChangedRows($seqAfterRow2);
        // Row 1 (originally row 3) was modified at seqNo 4, so it should appear
        // in changes since seqNo 3
        $this->assertContains(1, $changedRows);
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
    public function row_hash_invalidated_on_setCell(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');

        $hash1 = $buffer->getRowHash(0);

        // Modify the row using setCell
        $buffer->setCell(0, 0, new Cell('B'));

        $hash2 = $buffer->getRowHash(0);

        $this->assertNotEquals($hash1, $hash2, 'Row hash should change after setCell modifies the row');
    }

    #[Test]
    public function row_hash_invalidated_on_writeChar(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');

        $hash1 = $buffer->getRowHash(0);

        // Modify the row using writeChar
        $buffer->writeChar(0, 0, 'B');

        $hash2 = $buffer->getRowHash(0);

        $this->assertNotEquals($hash1, $hash2, 'Row hash should change after writeChar modifies the row');
    }

    #[Test]
    public function row_hash_invalidated_on_writeContinuation(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');
        $buffer->writeChar(0, 1, 'B');

        $hash1 = $buffer->getRowHash(0);

        // Modify the row using writeContinuation
        $buffer->writeContinuation(0, 1);

        $hash2 = $buffer->getRowHash(0);

        $this->assertNotEquals($hash1, $hash2, 'Row hash should change after writeContinuation modifies the row');
    }

    #[Test]
    public function row_hash_invalidated_on_clear(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');
        $buffer->writeChar(0, 1, 'B');

        $hash1 = $buffer->getRowHash(0);

        // Clear part of the row
        $buffer->clear(0, 0, 0, 0);

        $hash2 = $buffer->getRowHash(0);

        $this->assertNotEquals($hash1, $hash2, 'Row hash should change after clear modifies the row');
    }

    #[Test]
    public function row_hash_invalidated_on_clearLine(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');
        $buffer->writeChar(0, 1, 'B');

        $hash1 = $buffer->getRowHash(0);

        // Clear the entire row
        $buffer->clearLine(0);

        $hash2 = $buffer->getRowHash(0);

        $this->assertNotEquals($hash1, $hash2, 'Row hash should change after clearLine modifies the row');
    }

    #[Test]
    public function row_hash_invalidated_on_fill(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');

        $hash1 = $buffer->getRowHash(0);

        // Fill part of the row
        $buffer->fill('X', 0, 0, 5);

        $hash2 = $buffer->getRowHash(0);

        $this->assertNotEquals($hash1, $hash2, 'Row hash should change after fill modifies the row');
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

    #[Test]
    public function swap_buffers_enables_diff(): void
    {
        $buffer = new CellBuffer(10, 5);

        $this->assertFalse($buffer->hasPreviousFrame());

        $buffer->swapBuffers();

        $this->assertTrue($buffer->hasPreviousFrame());
    }

    #[Test]
    public function get_changed_cells_returns_all_without_previous_frame(): void
    {
        $buffer = new CellBuffer(5, 3);
        $buffer->writeChar(0, 0, 'A');

        $changed = $buffer->getChangedCells();

        // Without previous frame, all cells should be returned
        $this->assertCount(5 * 3, $changed);
    }

    #[Test]
    public function get_changed_cells_detects_single_change(): void
    {
        $buffer = new CellBuffer(5, 3);
        $buffer->writeChar(0, 0, 'A');
        $buffer->swapBuffers();

        // Make one change
        $buffer->writeChar(0, 0, 'B');

        $changed = $buffer->getChangedCells();

        // Only one cell should be changed
        $this->assertCount(1, $changed);
        $this->assertEquals(0, $changed[0]['row']);
        $this->assertEquals(0, $changed[0]['col']);
        $this->assertEquals('B', $changed[0]['cell']->char);
    }

    #[Test]
    public function get_changed_cells_detects_style_change(): void
    {
        $buffer = new CellBuffer(5, 3);
        $buffer->writeChar(0, 0, 'A');
        $buffer->swapBuffers();

        // Same char but different style
        $buffer->setStyle(1, 31, null, null, null);
        $buffer->writeChar(0, 0, 'A');

        $changed = $buffer->getChangedCells();

        $this->assertCount(1, $changed);
        $this->assertEquals(1, $changed[0]['cell']->style);
        $this->assertEquals(31, $changed[0]['cell']->fg);
    }

    #[Test]
    public function get_changed_cells_empty_when_no_changes(): void
    {
        $buffer = new CellBuffer(5, 3);
        $buffer->writeChar(0, 0, 'A');
        $buffer->swapBuffers();

        // No changes made
        $changed = $buffer->getChangedCells();

        $this->assertCount(0, $changed);
    }

    #[Test]
    public function get_changed_row_indices_returns_correct_rows(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');
        $buffer->writeChar(2, 0, 'B');
        $buffer->swapBuffers();

        // Change row 1 and row 3
        $buffer->writeChar(1, 5, 'X');
        $buffer->writeChar(3, 5, 'Y');

        $changedRows = $buffer->getChangedRowIndices();

        $this->assertCount(2, $changedRows);
        $this->assertContains(1, $changedRows);
        $this->assertContains(3, $changedRows);
    }

    #[Test]
    public function render_diff_generates_cursor_movements(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');
        $buffer->swapBuffers();

        // Change at position (2, 5)
        $buffer->writeChar(2, 5, 'X');

        $diff = $buffer->renderDiff();

        // Should contain cursor positioning for row 3, col 6 (1-indexed)
        $this->assertStringContainsString("\e[3;6H", $diff);
        $this->assertStringContainsString('X', $diff);
    }

    #[Test]
    public function render_diff_handles_styled_cells(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->swapBuffers();

        // Add styled change
        $buffer->setStyle(1, 31, null, null, null); // Bold red
        $buffer->writeChar(0, 0, 'R');

        $diff = $buffer->renderDiff();

        // Should contain style codes
        $this->assertStringContainsString('31', $diff); // Red
        $this->assertStringContainsString('R', $diff);
    }

    #[Test]
    public function render_diff_empty_when_no_changes(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->writeChar(0, 0, 'A');
        $buffer->swapBuffers();

        $diff = $buffer->renderDiff();

        $this->assertEquals('', $diff);
    }

    #[Test]
    public function render_diff_with_base_offset(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->swapBuffers();

        $buffer->writeChar(0, 0, 'X');

        // With base offset (10, 20), position (0, 0) becomes (11, 21) in ANSI
        $diff = $buffer->renderDiff(10, 20);

        $this->assertStringContainsString("\e[11;21H", $diff);
    }

    #[Test]
    public function benchmark_diff_vs_full_render(): void
    {
        $buffer = new CellBuffer(200, 50);

        // Fill the buffer
        for ($row = 0; $row < 50; $row++) {
            for ($col = 0; $col < 200; $col++) {
                $buffer->writeChar($row, $col, 'X');
            }
        }
        $buffer->swapBuffers();

        // Make a small change
        $buffer->writeChar(25, 100, 'O');

        // Time diff render
        $start = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $diff = $buffer->renderDiff();
        }
        $diffTime = hrtime(true) - $start;

        // Time full render
        $start = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $full = $buffer->render();
        }
        $fullTime = hrtime(true) - $start;

        // Diff should be significantly faster (at least 10x for single char change)
        $this->assertLessThan($fullTime / 5, $diffTime);
    }

    #[Test]
    public function benchmark_optimized_diff_vs_basic_diff(): void
    {
        $buffer = new CellBuffer(80, 25);

        // Fill with styled content (simulating log output)
        for ($row = 0; $row < 25; $row++) {
            $buffer->setStyle(1, 31 + ($row % 7), null, null, null); // Bold + colors
            for ($col = 0; $col < 80; $col++) {
                $buffer->writeChar($row, $col, 'X');
            }
        }
        $buffer->swapBuffers();

        // Make scattered changes (simulating typical diff scenario)
        $buffer->setStyle(1, 32, null, null, null); // Green
        $buffer->writeChar(5, 10, 'A');
        $buffer->writeChar(5, 11, 'B');
        $buffer->writeChar(5, 12, 'C');
        $buffer->writeChar(10, 20, 'D');
        $buffer->writeChar(10, 21, 'E');
        $buffer->writeChar(15, 0, 'F');
        $buffer->writeChar(15, 1, 'G');
        $buffer->writeChar(15, 2, 'H');

        // Get output from both methods
        $basicOutput = $buffer->renderDiff();
        $optimizedOutput = $buffer->renderDiffOptimized();

        $iterations = 1000;

        // Benchmark basic diff
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $buffer->renderDiff();
        }
        $basicTime = hrtime(true) - $start;

        // Benchmark optimized diff
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $buffer->renderDiffOptimized();
        }
        $optimizedTime = hrtime(true) - $start;

        $basicMs = $basicTime / 1_000_000;
        $optimizedMs = $optimizedTime / 1_000_000;
        $basicBytes = strlen($basicOutput);
        $optimizedBytes = strlen($optimizedOutput);

        echo "\n\nDiff Output Comparison ({$iterations} iterations, 8 cell changes):\n";
        echo '  Basic renderDiff:     ' . number_format($basicMs, 2) . " ms, {$basicBytes} bytes\n";
        echo '  Optimized renderDiff: ' . number_format($optimizedMs, 2) . " ms, {$optimizedBytes} bytes\n";
        echo '  Byte savings:         ' . round((1 - $optimizedBytes / $basicBytes) * 100, 1) . "%\n";

        // Optimized should produce smaller output
        $this->assertLessThan($basicBytes, $optimizedBytes);
    }

    #[Test]
    public function render_diff_optimized_produces_correct_output(): void
    {
        $buffer = new CellBuffer(10, 5);
        $buffer->swapBuffers();

        // Make a styled change
        $buffer->setStyle(1, 31, null, null, null); // Bold red
        $buffer->writeChar(2, 5, 'X');

        $output = $buffer->renderDiffOptimized();

        // Should contain cursor movement to row 2, col 5
        $this->assertNotEmpty($output);
        // Should contain style code
        $this->assertStringContainsString('31', $output);
        // Should contain the character
        $this->assertStringContainsString('X', $output);
    }
}
