<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Buffers;

use SoloTerm\Screen\Cell;
use SoloTerm\Screen\Output\CursorOptimizer;
use SoloTerm\Screen\Output\StyleTracker;

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
     * Flat array of Cell objects (current frame).
     *
     * @var Cell[]
     */
    protected array $cells = [];

    /**
     * Flat array of Cell objects (previous frame for diff).
     *
     * @var Cell[]
     */
    protected array $previousCells = [];

    /**
     * Whether we have a previous frame to compare against.
     */
    protected bool $hasPreviousFrame = false;

    /**
     * Tracks cell indices that have been modified since last swap.
     * Using index as key for O(1) lookup and dedup.
     *
     * @var array<int, true>
     */
    protected array $dirtyCells = [];

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
        $this->dirtyCells[$index] = true;
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
        $this->dirtyCells[$index] = true;
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
        $this->dirtyCells[$index] = true;
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
                $this->dirtyCells[$index] = true;
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
            $this->dirtyCells[$index] = true;
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
            $this->dirtyCells[$index] = true;
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
     * Cached row hashes for O(1) row comparison.
     *
     * @var array<int, int>
     */
    protected array $rowHashes = [];

    /**
     * Check if a row is identical to the same row in another CellBuffer.
     *
     * Uses cached row hashes for O(1) comparison of unchanged rows.
     */
    public function rowEquals(int $row, CellBuffer $other): bool
    {
        // Different widths means rows can't be equal
        if ($this->width !== $other->getWidth()) {
            return false;
        }

        // Compare row hashes (O(1) for cached hashes)
        return $this->getRowHash($row) === $other->getRowHash($row);
    }

    /**
     * Get a hash representing the content of a row.
     *
     * Uses a fast polynomial rolling hash for efficient comparison.
     * The hash is cached for efficient repeated comparisons.
     */
    public function getRowHash(int $row): int
    {
        if (isset($this->rowHashes[$row])) {
            return $this->rowHashes[$row];
        }

        // Use polynomial rolling hash - much faster than string concat + MD5
        $baseIndex = $row * $this->width;
        $hash = 0;

        for ($col = 0; $col < $this->width; $col++) {
            $cell = $this->cells[$baseIndex + $col] ?? Cell::blank();

            // Hash the character (using ord for single-byte, crc32 for multi-byte)
            $charHash = strlen($cell->char) === 1 ? ord($cell->char) : crc32($cell->char);

            // Combine all cell properties into hash using prime multiplier
            $hash = (($hash * 31) + $charHash) & 0x7FFFFFFF;
            $hash = (($hash * 31) + $cell->style) & 0x7FFFFFFF;
            $hash = (($hash * 31) + ($cell->fg ?? -1)) & 0x7FFFFFFF;
            $hash = (($hash * 31) + ($cell->bg ?? -1)) & 0x7FFFFFFF;

            // Handle extended colors (RGB)
            if ($cell->extFg) {
                $hash = (($hash * 31) + $cell->extFg[0]) & 0x7FFFFFFF;
                $hash = (($hash * 31) + $cell->extFg[1]) & 0x7FFFFFFF;
                $hash = (($hash * 31) + $cell->extFg[2]) & 0x7FFFFFFF;
            }
            if ($cell->extBg) {
                $hash = (($hash * 31) + $cell->extBg[0]) & 0x7FFFFFFF;
                $hash = (($hash * 31) + $cell->extBg[1]) & 0x7FFFFFFF;
                $hash = (($hash * 31) + $cell->extBg[2]) & 0x7FFFFFFF;
            }
        }

        $this->rowHashes[$row] = $hash;

        return $this->rowHashes[$row];
    }

    /**
     * Invalidate the cached hash for a row.
     *
     * Called automatically when cells in the row are modified.
     */
    protected function invalidateRowHash(int $row): void
    {
        unset($this->rowHashes[$row]);
    }

    /**
     * Get the raw cells array slice for a row.
     *
     * This provides direct access to the underlying array for efficient comparison.
     *
     * @return Cell[]
     */
    public function getRowSlice(int $row): array
    {
        $baseIndex = $row * $this->width;

        return array_slice($this->cells, $baseIndex, $this->width);
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
     * Swap the current buffer to the previous buffer for diff comparison.
     * Call this after rendering a frame.
     */
    public function swapBuffers(): void
    {
        $this->previousCells = $this->cells;
        $this->hasPreviousFrame = true;
        $this->dirtyCells = [];
    }

    /**
     * Check if we have a previous frame to compare against.
     */
    public function hasPreviousFrame(): bool
    {
        return $this->hasPreviousFrame;
    }

    /**
     * Get all cells that have changed since the last swap.
     *
     * Uses dirty cell tracking for O(dirty cells) instead of O(all cells).
     *
     * @return array<array{row: int, col: int, cell: Cell}> Array of changed cells with positions
     */
    public function getChangedCells(): array
    {
        if (!$this->hasPreviousFrame) {
            // No previous frame - all cells are "changed"
            $changed = [];
            for ($row = 0; $row < $this->height; $row++) {
                for ($col = 0; $col < $this->width; $col++) {
                    $index = $row * $this->width + $col;
                    $changed[] = [
                        'row' => $row,
                        'col' => $col,
                        'cell' => $this->cells[$index] ?? Cell::blank(),
                    ];
                }
            }

            return $changed;
        }

        // Only check cells that were actually modified since last swap
        $changed = [];

        foreach ($this->dirtyCells as $index => $_) {
            $currentCell = $this->cells[$index] ?? Cell::blank();
            $previousCell = $this->previousCells[$index] ?? Cell::blank();

            // Only report if actually different (cell might be written with same value)
            if (!$currentCell->equals($previousCell)) {
                $row = intdiv($index, $this->width);
                $col = $index % $this->width;
                $changed[] = [
                    'row' => $row,
                    'col' => $col,
                    'cell' => $currentCell,
                ];
            }
        }

        // Sort by row then column for consistent output order
        usort($changed, function ($a, $b) {
            if ($a['row'] !== $b['row']) {
                return $a['row'] - $b['row'];
            }

            return $a['col'] - $b['col'];
        });

        return $changed;
    }

    /**
     * Render only the changed cells with cursor positioning.
     *
     * Returns ANSI escape sequences that move the cursor and update only changed cells.
     * This is more efficient than re-rendering the entire screen.
     *
     * @param  int  $baseRow  The base row offset in the terminal (for embedding in larger displays)
     * @param  int  $baseCol  The base column offset in the terminal
     * @return string ANSI output for differential update
     */
    public function renderDiff(int $baseRow = 0, int $baseCol = 0): string
    {
        $changedCells = $this->getChangedCells();

        if (empty($changedCells)) {
            return '';
        }

        $output = '';
        $lastRow = -1;
        $lastCol = -1;
        $previousCell = null;

        foreach ($changedCells as $change) {
            $row = $change['row'];
            $col = $change['col'];
            $cell = $change['cell'];

            // Skip continuation cells
            if ($cell->isContinuation()) {
                continue;
            }

            // Position cursor if not already at the right place
            $targetRow = $baseRow + $row + 1; // ANSI is 1-indexed
            $targetCol = $baseCol + $col + 1;

            if ($lastRow !== $row || $lastCol !== $col) {
                $output .= "\e[{$targetRow};{$targetCol}H";
            }

            // Add style transition and character
            $output .= $cell->getStyleTransition($previousCell);
            $output .= $cell->char;

            $previousCell = $cell;
            $lastRow = $row;
            $lastCol = $col + 1; // We moved one column right
        }

        // Reset styles at the end
        if ($previousCell !== null && $previousCell->hasStyle()) {
            $output .= "\e[0m";
        }

        return $output;
    }

    /**
     * Render only the changed cells with optimized cursor movement and style tracking.
     *
     * This method uses CursorOptimizer and StyleTracker to minimize the output size
     * by choosing efficient cursor movements and avoiding redundant style codes.
     *
     * @param  int  $baseRow  The base row offset in the terminal (for embedding in larger displays)
     * @param  int  $baseCol  The base column offset in the terminal
     * @return string Optimized ANSI output for differential update
     */
    public function renderDiffOptimized(int $baseRow = 0, int $baseCol = 0): string
    {
        $changedCells = $this->getChangedCells();

        if (empty($changedCells)) {
            return '';
        }

        $cursor = new CursorOptimizer;
        $style = new StyleTracker;
        $parts = [];

        foreach ($changedCells as $change) {
            $row = $change['row'];
            $col = $change['col'];
            $cell = $change['cell'];

            // Skip continuation cells
            if ($cell->isContinuation()) {
                continue;
            }

            // Calculate target position (with base offset, 0-indexed for optimizer)
            $targetRow = $baseRow + $row;
            $targetCol = $baseCol + $col;

            // Get optimized cursor movement
            $parts[] = $cursor->moveTo($targetRow, $targetCol);

            // Get optimized style transition
            $parts[] = $style->transitionTo($cell);

            // Output the character
            $parts[] = $cell->char;

            // Track cursor position after character
            $cursor->advance(1);
        }

        // Reset styles at the end if needed
        $parts[] = $style->resetIfNeeded();

        return implode('', $parts);
    }

    /**
     * Get rows that have any changed cells.
     *
     * More efficient than getChangedCells() when you only need row-level granularity.
     * Uses dirty cell tracking for fast lookup.
     *
     * @return array<int> Row indices with changes
     */
    public function getChangedRowIndices(): array
    {
        if (!$this->hasPreviousFrame) {
            return range(0, $this->height - 1);
        }

        $changedRows = [];

        foreach ($this->dirtyCells as $index => $_) {
            $currentCell = $this->cells[$index] ?? Cell::blank();
            $previousCell = $this->previousCells[$index] ?? Cell::blank();

            if (!$currentCell->equals($previousCell)) {
                $row = intdiv($index, $this->width);
                $changedRows[$row] = true;
            }
        }

        $result = array_keys($changedRows);
        sort($result);

        return $result;
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

        // Shift lineSeqNos entries for rows >= $atRow up by $count.
        // Iterate in descending order to avoid overwriting entries.
        $keys = array_keys($this->lineSeqNos);
        rsort($keys, SORT_NUMERIC);
        foreach ($keys as $row) {
            if ($row >= $atRow) {
                $this->lineSeqNos[$row + $count] = $this->lineSeqNos[$row];
                unset($this->lineSeqNos[$row]);
            }
        }

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

        // Update lineSeqNos: remove entries for deleted rows and shift
        // entries for rows >= $atRow + $count down by $count.
        $newLineSeqNos = [];
        foreach ($this->lineSeqNos as $row => $seqNo) {
            if ($row < $atRow) {
                // Rows before deletion point are unchanged
                $newLineSeqNos[$row] = $seqNo;
            } elseif ($row >= $atRow + $count) {
                // Rows after deleted region shift down by $count
                $newLineSeqNos[$row - $count] = $seqNo;
            }
            // Rows in the deleted range [$atRow, $atRow + $count) are discarded
        }
        $this->lineSeqNos = $newLineSeqNos;

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
