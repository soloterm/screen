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

class ScreenCellBufferSyncTest extends TestCase
{
    #[Test]
    public function identical_frame_does_not_rematerialize_visible_rows(): void
    {
        $screen = new InstrumentedScreen(8, 3);
        $screen->write("one\ntwo\nthree");

        $buffer = new CellBuffer(8, 3);

        $screen->toCellBuffer($buffer);
        $this->assertSame(3, $screen->materializedRows);

        $screen->materializedRows = 0;
        $screen->toCellBuffer($buffer);

        $this->assertSame(0, $screen->materializedRows);
    }

    #[Test]
    public function single_changed_visible_row_is_the_only_row_rematerialized(): void
    {
        $screen = new InstrumentedScreen(8, 3);
        $screen->write("one\ntwo\nsix");

        $buffer = new CellBuffer(8, 3);
        $screen->toCellBuffer($buffer);

        $screen->materializedRows = 0;
        $screen->write("\e[2;1HTWO");
        $screen->toCellBuffer($buffer);

        $this->assertSame(1, $screen->materializedRows);
        $this->assertSame('one', $this->rowText($buffer, 0));
        $this->assertSame('TWO', $this->rowText($buffer, 1));
        $this->assertSame('six', $this->rowText($buffer, 2));
    }

    #[Test]
    public function dirty_rows_only_rewrite_changed_cells_inside_the_row(): void
    {
        $screen = new InstrumentedScreen(8, 2);
        $screen->write("hello\nworld");

        $buffer = new CountingCellBuffer(8, 2);
        $screen->toCellBuffer($buffer);

        $screen->materializedRows = 0;
        $buffer->setCellCalls = 0;
        $screen->write("\e[1;2HXX");
        $screen->toCellBuffer($buffer);

        $this->assertSame(1, $screen->materializedRows);
        $this->assertSame(2, $buffer->setCellCalls);
        $this->assertSame('hXXlo', $this->rowText($buffer, 0));
    }

    #[Test]
    public function dirty_rows_only_scan_the_changed_span(): void
    {
        $screen = new InstrumentedScreen(24, 1);
        $screen->write('abcdefghijklmnopqrstuvwx');

        $buffer = new CellBuffer(24, 1);
        $screen->toCellBuffer($buffer);

        $screen->materializedRows = 0;
        $screen->scannedColumns = 0;
        $screen->write("\e[1;11HZZ");
        $screen->toCellBuffer($buffer);

        $this->assertSame(1, $screen->materializedRows);
        $this->assertSame(2, $screen->scannedColumns);
        $this->assertSame('abcdefghijZZmnopqrstuvwx', $this->rowText($buffer, 0));
    }

    #[Test]
    public function no_op_writes_do_not_rewrite_matching_cells_in_dirty_rows(): void
    {
        $screen = new InstrumentedScreen(8, 2);
        $screen->write("hello\nworld");

        $buffer = new CountingCellBuffer(8, 2);
        $screen->toCellBuffer($buffer);

        $screen->materializedRows = 0;
        $buffer->setCellCalls = 0;
        $screen->write("\e[1;1Hhello");
        $screen->toCellBuffer($buffer);

        $this->assertSame(1, $screen->materializedRows);
        $this->assertSame(0, $buffer->setCellCalls);
        $this->assertSame('hello', $this->rowText($buffer, 0));
    }

    #[Test]
    public function viewport_remap_forces_a_full_visible_resync_even_without_new_writes(): void
    {
        $screen = new InstrumentedScreen(4, 3);
        $screen->write("1\n2\n3\n4");

        $buffer = new CellBuffer(4, 3);
        $screen->toCellBuffer($buffer);

        $screen->materializedRows = 0;
        $screen->linesOffScreen = 0;
        $screen->toCellBuffer($buffer);

        $this->assertSame(3, $screen->materializedRows);
        $this->assertSame('1', $this->rowText($buffer, 0));
        $this->assertSame('2', $this->rowText($buffer, 1));
        $this->assertSame('3', $this->rowText($buffer, 2));
    }

    #[Test]
    public function resize_invalidates_the_reused_buffer_in_place(): void
    {
        $screen = new InstrumentedScreen(6, 2);
        $screen->write("abcdef\nghijkl");

        $buffer = new CellBuffer(6, 2);
        $screen->toCellBuffer($buffer);

        $screen->materializedRows = 0;
        $screen->resize(4, 2);

        $result = $screen->toCellBuffer($buffer);

        $this->assertSame($buffer, $result);
        $this->assertSame(2, $screen->materializedRows);
        $this->assertSame(4, $buffer->getWidth());
        $this->assertSame(2, $buffer->getHeight());
        $this->assertSame('abcd', $this->rowText($buffer, 0));
        $this->assertSame('ghij', $this->rowText($buffer, 1));
    }

    #[Test]
    public function wide_characters_and_continuations_survive_incremental_row_reuse(): void
    {
        $screen = new InstrumentedScreen(6, 2);
        $screen->write("ab文c\nrow2");

        $buffer = new CellBuffer(6, 2);
        $screen->toCellBuffer($buffer);

        $screen->materializedRows = 0;
        $screen->write("\e[2;1HROW2");
        $screen->toCellBuffer($buffer);

        $this->assertSame(1, $screen->materializedRows);
        $this->assertSame('文', $buffer->getCell(0, 2)->char);
        $this->assertTrue($buffer->getCell(0, 3)->isContinuation());
        $this->assertSame('c', $buffer->getCell(0, 4)->char);
        $this->assertSame('ROW2', $this->rowText($buffer, 1));
    }

    #[Test]
    public function ansi_style_updates_on_a_partial_row_are_preserved_incrementally(): void
    {
        $screen = new InstrumentedScreen(8, 2);
        $screen->write("hello\nworld");

        $buffer = new CellBuffer(8, 2);
        $screen->toCellBuffer($buffer);

        $screen->materializedRows = 0;
        $screen->write("\e[1;2H\e[38;5;196mel\e[0m");
        $screen->toCellBuffer($buffer);

        $this->assertSame(1, $screen->materializedRows);
        $this->assertSame('h', $buffer->getCell(0, 0)->char);
        $this->assertNull($buffer->getCell(0, 0)->extFg);
        $this->assertSame('e', $buffer->getCell(0, 1)->char);
        $this->assertSame([5, 196], $buffer->getCell(0, 1)->extFg);
        $this->assertSame('l', $buffer->getCell(0, 2)->char);
        $this->assertSame([5, 196], $buffer->getCell(0, 2)->extFg);
        $this->assertNull($buffer->getCell(0, 3)->extFg);
    }

    private function rowText(CellBuffer $buffer, int $row): string
    {
        return rtrim(implode('', array_map(
            static fn(Cell $cell) => $cell->char,
            $buffer->getRow($row)
        )));
    }
}

final class InstrumentedScreen extends Screen
{
    public int $materializedRows = 0;
    public int $scannedColumns = 0;

    protected function materializeCellBufferRow(
        CellBuffer $buffer,
        int $targetRow,
        array $printableLine,
        array $ansiLine,
        int $startCol = 0,
        ?int $endCol = null
    ): void {
        $this->materializedRows++;
        $lastCol = $endCol ?? ($this->width - 1);
        $this->scannedColumns += max(0, $lastCol - $startCol + 1);

        parent::materializeCellBufferRow($buffer, $targetRow, $printableLine, $ansiLine, $startCol, $endCol);
    }
}

final class CountingCellBuffer extends CellBuffer
{
    public int $setCellCalls = 0;

    public function setCell(int $row, int $col, Cell $cell): void
    {
        $this->setCellCalls++;

        parent::setCell($row, $col, $cell);
    }
}
