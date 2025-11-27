<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Buffers;

use SoloTerm\Screen\Cell;

/**
 * A unified buffer that stores Cell objects in a flat array.
 *
 * This combines the functionality of PrintableBuffer and AnsiBuffer
 * into a single data structure for more efficient access and comparison.
 *
 * Uses flat array indexing: $cells[$y * $width + $x] for O(1) access.
 */
class CellBuffer
{
    /**
     * Flat array of Cell objects.
     *
     * @var Cell[]
     */
    protected array $cells = [];

    /**
     * Buffer width in columns.
     */
    protected int $width;

    /**
     * Buffer height in rows (may grow dynamically).
     */
    protected int $height;

    /**
     * Maximum number of rows to retain (for memory management).
     */
    protected int $maxRows;

    /**
     * Number of rows that have scrolled off the top.
     */
    protected int $scrollOffset = 0;

    /**
     * Tracks the sequence number when each line was last modified.
     *
     * @var array<int, int>
     */
    protected array $lineSeqNos = [];

    /**
     * Callback to get current sequence number.
     *
     * @var callable|null
     */
    protected $seqNoProvider = null;

    /**
     * The current ANSI styling state (applied to new writes).
     */
    protected Cell $currentStyle;

    public function __construct(int $width, int $height, int $maxRows = 5000)
    {
        $this->width = $width;
        $this->height = $height;
        $this->maxRows = $maxRows;
        $this->currentStyle = Cell::blank();

        // Initialize buffer with blank cells
        $this->initializeRows(0, $height);
    }

    /**
     * Initialize rows with blank cells.
     */
    protected function initializeRows(int $startRow, int $endRow): void
    {
        for ($y = $startRow; $y < $endRow; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $this->cells[$y * $this->width + $x] = Cell::blank();
            }
        }
    }

    /**
     * Set a callback that returns the current sequence number.
     */
    public function setSeqNoProvider(callable $provider): static
    {
        $this->seqNoProvider = $provider;

        return $this;
    }

    /**
     * Mark a line as dirty (modified) with the current sequence number.
     */
    public function markLineDirty(int $row): void
    {
        if ($this->seqNoProvider !== null) {
            $this->lineSeqNos[$row] = ($this->seqNoProvider)();
        }
    }

    /**
     * Get all rows that have changed since the given sequence number.
     *
     * @return array<int> Row indices that have changed
     */
    public function getChangedRows(int $sinceSeqNo): array
    {
        $changed = [];

        foreach ($this->lineSeqNos as $row => $rowSeqNo) {
            if ($rowSeqNo > $sinceSeqNo) {
                $changed[] = $row;
            }
        }

        sort($changed);

        return $changed;
    }

    /**
     * Get a cell at the specified position.
     */
    public function getCell(int $row, int $col): Cell
    {
        $index = $row * $this->width + $col;

        return $this->cells[$index] ?? Cell::blank();
    }

    /**
     * Set a cell at the specified position.
     */
    public function setCell(int $row, int $col, Cell $cell): void
    {
        $this->ensureRow($row);
        $index = $row * $this->width + $col;
        $this->cells[$index] = $cell;
        $this->markLineDirty($row);
    }

    /**
     * Write a character at the specified position with current styling.
     */
    public function writeChar(int $row, int $col, string $char): void
    {
        $this->ensureRow($row);

        $cell = new Cell(
            $char,
            $this->currentStyle->style,
            $this->currentStyle->fg,
            $this->currentStyle->bg,
            $this->currentStyle->extFg,
            $this->currentStyle->extBg
        );

        $index = $row * $this->width + $col;
        $this->cells[$index] = $cell;
        $this->markLineDirty($row);
    }

    /**
     * Write a continuation cell (for wide characters).
     */
    public function writeContinuation(int $row, int $col): void
    {
        $this->ensureRow($row);

        $cell = Cell::continuation();
        $cell->style = $this->currentStyle->style;
        $cell->fg = $this->currentStyle->fg;
        $cell->bg = $this->currentStyle->bg;
        $cell->extFg = $this->currentStyle->extFg;
        $cell->extBg = $this->currentStyle->extBg;

        $index = $row * $this->width + $col;
        $this->cells[$index] = $cell;
    }

    /**
     * Clear a region of the buffer.
     */
    public function clear(
        int $startRow = 0,
        int $startCol = 0,
        int $endRow = PHP_INT_MAX,
        int $endCol = PHP_INT_MAX
    ): void {
        $endRow = min($endRow, $this->height - 1);
        $endCol = min($endCol, $this->width - 1);

        for ($row = $startRow; $row <= $endRow; $row++) {
            if (!$this->rowExists($row)) {
                continue;
            }

            $colStart = ($row === $startRow) ? $startCol : 0;
            $colEnd = ($row === $endRow) ? $endCol : $this->width - 1;

            for ($col = $colStart; $col <= $colEnd; $col++) {
                $index = $row * $this->width + $col;
                $this->cells[$index] = Cell::blank();
            }

            $this->markLineDirty($row);
        }
    }

    /**
     * Clear an entire line.
     */
    public function clearLine(int $row): void
    {
        if (!$this->rowExists($row)) {
            return;
        }

        for ($col = 0; $col < $this->width; $col++) {
            $index = $row * $this->width + $col;
            $this->cells[$index] = Cell::blank();
        }

        $this->markLineDirty($row);
    }

    /**
     * Fill a region with a character using current styling.
     */
    public function fill(string $char, int $row, int $startCol, int $endCol): void
    {
        $this->ensureRow($row);

        $cell = new Cell(
            $char,
            $this->currentStyle->style,
            $this->currentStyle->fg,
            $this->currentStyle->bg,
            $this->currentStyle->extFg,
            $this->currentStyle->extBg
        );

        for ($col = $startCol; $col <= $endCol; $col++) {
            $index = $row * $this->width + $col;
            $this->cells[$index] = clone $cell;
        }

        $this->markLineDirty($row);
    }

    /**
     * Set the current ANSI styling state.
     */
    public function setStyle(int $style, ?int $fg, ?int $bg, ?array $extFg, ?array $extBg): void
    {
        $this->currentStyle = new Cell(' ', $style, $fg, $bg, $extFg, $extBg);
    }

    /**
     * Get the current styling state.
     */
    public function getCurrentStyle(): Cell
    {
        return $this->currentStyle;
    }

    /**
     * Reset styling to defaults.
     */
    public function resetStyle(): void
    {
        $this->currentStyle = Cell::blank();
    }

    /**
     * Ensure a row exists in the buffer, expanding if necessary.
     */
    public function ensureRow(int $row): void
    {
        while ($this->height <= $row) {
            $this->initializeRows($this->height, $this->height + 1);
            $this->height++;
        }

        $this->trim();
    }

    /**
     * Check if a row exists in the buffer.
     */
    public function rowExists(int $row): bool
    {
        return $row >= 0 && $row < $this->height;
    }

    /**
     * Get a row as an array of Cells.
     *
     * @return Cell[]
     */
    public function getRow(int $row): array
    {
        if (!$this->rowExists($row)) {
            return array_fill(0, $this->width, Cell::blank());
        }

        $cells = [];
        $baseIndex = $row * $this->width;

        for ($col = 0; $col < $this->width; $col++) {
            $cells[] = $this->cells[$baseIndex + $col] ?? Cell::blank();
        }

        return $cells;
    }

    /**
     * Render a row to a string with ANSI codes.
     */
    public function renderRow(int $row): string
    {
        $cells = $this->getRow($row);
        $output = '';
        $previousCell = null;

        foreach ($cells as $cell) {
            // Skip continuation cells (they don't render anything)
            if ($cell->isContinuation()) {
                $previousCell = $cell;

                continue;
            }

            $output .= $cell->getStyleTransition($previousCell);
            $output .= $cell->char;
            $previousCell = $cell;
        }

        return $output;
    }

    /**
     * Render the entire buffer to a string.
     */
    public function render(): string
    {
        $lines = [];

        for ($row = 0; $row < $this->height; $row++) {
            $lines[] = $this->renderRow($row);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Get the buffer dimensions.
     *
     * @return array{width: int, height: int}
     */
    public function getDimensions(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    /**
     * Get buffer width.
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Get buffer height.
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Trim old rows if buffer exceeds max size.
     */
    protected function trim(): void
    {
        // 95% chance of skipping (same as original Buffer)
        if (rand(1, 100) <= 95) {
            return;
        }

        $excess = $this->height - $this->maxRows;

        if ($excess > 0) {
            // Remove oldest rows
            array_splice($this->cells, 0, $excess * $this->width);
            $this->height -= $excess;
            $this->scrollOffset += $excess;

            // Update line sequence numbers
            $newLineSeqNos = [];
            foreach ($this->lineSeqNos as $row => $seqNo) {
                if ($row >= $excess) {
                    $newLineSeqNos[$row - $excess] = $seqNo;
                }
            }
            $this->lineSeqNos = $newLineSeqNos;
        }
    }

    /**
     * Insert blank lines at the specified row.
     */
    public function insertLines(int $atRow, int $count): void
    {
        $this->ensureRow($atRow);

        // Create blank cells for new lines
        $newCells = [];
        for ($i = 0; $i < $count * $this->width; $i++) {
            $newCells[] = Cell::blank();
        }

        // Insert at the correct position
        $insertIndex = $atRow * $this->width;
        array_splice($this->cells, $insertIndex, 0, $newCells);

        $this->height += $count;

        // Mark all affected rows as dirty
        for ($row = $atRow; $row < $this->height; $row++) {
            $this->markLineDirty($row);
        }

        $this->trim();
    }

    /**
     * Delete lines at the specified row.
     */
    public function deleteLines(int $atRow, int $count): void
    {
        if (!$this->rowExists($atRow)) {
            return;
        }

        $count = min($count, $this->height - $atRow);
        $deleteIndex = $atRow * $this->width;
        $deleteCount = $count * $this->width;

        array_splice($this->cells, $deleteIndex, $deleteCount);
        $this->height -= $count;

        // Mark all affected rows as dirty
        for ($row = $atRow; $row < $this->height; $row++) {
            $this->markLineDirty($row);
        }
    }

    /**
     * Scroll the buffer up by inserting lines at the bottom.
     */
    public function scrollUp(int $lines = 1): void
    {
        // Delete from top
        $this->deleteLines(0, $lines);

        // Insert at bottom
        for ($i = 0; $i < $lines; $i++) {
            $this->ensureRow($this->height);
        }
    }

    /**
     * Scroll the buffer down by inserting lines at the top.
     */
    public function scrollDown(int $lines = 1): void
    {
        $this->insertLines(0, $lines);

        // Trim excess from bottom if needed
        if ($this->height > $this->maxRows) {
            $excess = $this->height - $this->maxRows;
            $this->deleteLines($this->height - $excess, $excess);
        }
    }
}
