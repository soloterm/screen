<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Buffers;

use ArrayAccess;
use ReturnTypeWillChange;

class Buffer implements ArrayAccess
{
    public array $buffer = [];

    protected mixed $valueForClearing = 0;

    /**
     * Tracks the sequence number when each line was last modified.
     * Used for differential rendering - only lines with seqNo > lastRendered need re-rendering.
     *
     * @var array<int, int>
     */
    protected array $lineSeqNos = [];

    /**
     * Reference to the parent screen's sequence counter.
     * Set via setSeqNoProvider() to avoid circular dependencies.
     *
     * @var callable|null
     */
    protected $seqNoProvider = null;

    /**
     * Conservative dirty column spans for each row.
     *
     * @var array<int, array{0:int,1:int}>
     */
    protected array $lineDirtySpans = [];

    /**
     * Whether this buffer should keep exact dirty-span history by sequence.
     */
    protected bool $trackDirtySpanHistory = false;

    /**
     * Per-row dirty span history keyed by row.
     *
     * Entries are stored as [seqNo, startCol, endCol]. Older entries are
     * conservatively compacted so getChangedSpan() can still answer "since
     * seq" queries without keeping an unbounded exact log.
     *
     * @var array<int, list<array{0:int,1:int,2:int}>>
     */
    protected array $lineDirtySpanHistory = [];

    public function __construct(public int $max = 5000)
    {
        if (method_exists($this, 'initialize')) {
            $this->initialize();
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
    public function markLineDirty(int $row, ?int $startCol = null, ?int $endCol = null): void
    {
        if ($this->seqNoProvider === null) {
            return;
        }

        $seqNo = ($this->seqNoProvider)();
        $this->lineSeqNos[$row] = $seqNo;

        if ($this->trackDirtySpanHistory) {
            $this->recordDirtySpanHistory($row, $seqNo, $startCol, $endCol);

            return;
        }

        $this->mergeDirtySpan($row, $startCol, $endCol);
    }

    /**
     * Check if a line has changed since the given sequence number.
     */
    public function lineChangedSince(int $row, int $seqNo): bool
    {
        return ($this->lineSeqNos[$row] ?? 0) > $seqNo;
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
     * Get the dirty column span for a row if it changed.
     *
     * @return array{0:int,1:int}|null
     */
    public function getChangedSpan(int $row, int $sinceSeqNo): ?array
    {
        if (!$this->lineChangedSince($row, $sinceSeqNo)) {
            return null;
        }

        if (!isset($this->lineDirtySpanHistory[$row])) {
            return $this->lineDirtySpans[$row] ?? [0, PHP_INT_MAX];
        }

        if (!$this->trackDirtySpanHistory) {
            return $this->lineDirtySpans[$row] ?? [0, PHP_INT_MAX];
        }

        $startCol = PHP_INT_MAX;
        $endCol = -1;

        foreach ($this->lineDirtySpanHistory[$row] as [$seqNo, $entryStart, $entryEnd]) {
            if ($seqNo <= $sinceSeqNo) {
                continue;
            }

            $startCol = min($startCol, $entryStart);
            $endCol = max($endCol, $entryEnd);
        }

        if ($endCol === -1) {
            return [0, PHP_INT_MAX];
        }

        return [$startCol, $endCol];
    }

    /**
     * Get the maximum sequence number across all lines.
     */
    public function getMaxSeqNo(): int
    {
        return empty($this->lineSeqNos) ? 0 : max($this->lineSeqNos);
    }

    public function getBuffer()
    {
        return $this->buffer;
    }

    public function clear(
        int $startRow = 0,
        int $startCol = 0,
        int $endRow = PHP_INT_MAX,
        int $endCol = PHP_INT_MAX
    ): void {
        // Short-circuit if we're clearing the whole buffer.
        if ($startRow === 0 && $startCol === 0 && $endRow === PHP_INT_MAX && $endCol === PHP_INT_MAX) {
            // Mark all existing rows as dirty before clearing
            foreach (array_keys($this->buffer) as $row) {
                $this->markLineDirty($row);
            }
            $this->buffer = [];

            return;
        }

        $endRow = min($endRow, count($this->buffer) - 1);

        for ($row = $startRow; $row <= $endRow; $row++) {
            if (!array_key_exists($row, $this->buffer)) {
                continue;
            }
            $cols = $this->normalizeClearColumns($row, $startRow, $startCol, $endRow, $endCol);

            $line = $this->buffer[$row];
            $length = $this->rowMax($row);

            if ($cols[0] === 0 && $cols[1] === $length) {
                // Clearing an entire line. Benchmarked slightly
                // faster to just replace the entire row.
                $this->buffer[$row] = [];
                $this->markLineDirty($row);
            } elseif ($cols[0] > 0 && $cols[1] === $length) {
                // Clearing from cols[0] to the end of the line.
                // Chop off the end of the array.
                $this->buffer[$row] = array_slice($line, 0, $cols[0]);
                $this->markLineDirty($row, $cols[0], $length);
            } else {
                // Clearing the middle of a row. Fill with the replacement value.
                $this->fill($this->valueForClearing, $row, $cols[0], $cols[1]);

                continue;
            }
        }
    }

    public function expand($rows)
    {
        while (count($this->buffer) <= $rows) {
            $this->buffer[] = [];
        }
    }

    public function fill(mixed $value, int $row, int $startCol, int $endCol)
    {
        $this->expand($row);
        $line = &$this->buffer[$row];

        if ($startCol <= $endCol) {
            for ($col = $startCol; $col <= $endCol; $col++) {
                $line[$col] = $value;
            }
        } else {
            for ($col = $startCol; $col >= $endCol; $col--) {
                $line[$col] = $value;
            }
        }

        $this->markLineDirty($row, $startCol, $endCol);
        $this->trim();
    }

    public function resizeWidth(int $width): static
    {
        foreach ($this->buffer as $row => $line) {
            $trimmed = [];

            foreach ($line as $col => $value) {
                if ($col >= $width) {
                    break;
                }

                $trimmed[$col] = $value;
            }

            if ($trimmed !== $line) {
                $this->buffer[$row] = $trimmed;
                $this->markLineDirty($row);
            }
        }

        return $this;
    }

    public function rowMax($row)
    {
        return count($this->buffer[$row]) - 1;
    }

    public function trim()
    {
        // 95% chance of just doing nothing.
        if (rand(1, 100) <= 95) {
            return;
        }

        $excess = count($this->buffer) - $this->max;

        // Clear out old rows. Hopefully this helps save memory.
        // @link https://github.com/aarondfrancis/solo/issues/33
        if ($excess > 0) {
            $keys = array_keys($this->buffer);
            $remove = array_slice($keys, 0, $excess);
            $nulls = array_fill_keys($remove, []);

            $this->buffer = array_replace($this->buffer, $nulls);
        }
    }

    protected function recordDirtySpanHistory(int $row, int $seqNo, ?int $startCol, ?int $endCol): void
    {
        if ($startCol === null || $endCol === null) {
            $this->lineDirtySpanHistory[$row] = [[$seqNo, 0, PHP_INT_MAX]];
            $this->lineDirtySpans[$row] = [0, PHP_INT_MAX];

            return;
        }

        if ($endCol < $startCol) {
            [$startCol, $endCol] = [$endCol, $startCol];
        }

        $history = $this->lineDirtySpanHistory[$row] ?? [];
        $history[] = [$seqNo, $startCol, $endCol];
        $this->mergeDirtySpan($row, $startCol, $endCol);

        while (count($history) > 6) {
            $offset = isset($history[0]) && $history[0][1] === 0 && $history[0][2] === PHP_INT_MAX
                ? 1
                : 0;

            if (!isset($history[$offset + 1])) {
                break;
            }

            [$firstSeqNo, $firstStart, $firstEnd] = $history[$offset];
            [$secondSeqNo, $secondStart, $secondEnd] = $history[$offset + 1];

            array_splice($history, $offset, 2, [[
                max($firstSeqNo, $secondSeqNo),
                min($firstStart, $secondStart),
                max($firstEnd, $secondEnd),
            ]]);
        }

        $this->lineDirtySpanHistory[$row] = $history;
    }

    protected function mergeDirtySpan(int $row, ?int $startCol, ?int $endCol): void
    {
        if ($startCol === null || $endCol === null) {
            $this->lineDirtySpans[$row] = [0, PHP_INT_MAX];

            return;
        }

        if ($endCol < $startCol) {
            [$startCol, $endCol] = [$endCol, $startCol];
        }

        if (!isset($this->lineDirtySpans[$row])) {
            $this->lineDirtySpans[$row] = [$startCol, $endCol];

            return;
        }

        if ($this->lineDirtySpans[$row][1] === PHP_INT_MAX) {
            return;
        }

        $this->lineDirtySpans[$row] = [
            min($this->lineDirtySpans[$row][0], $startCol),
            max($this->lineDirtySpans[$row][1], $endCol),
        ];
    }

    protected function normalizeClearColumns(int $currentRow, int $startRow, int $startCol, int $endRow, int $endCol)
    {
        if ($startRow === $endRow) {
            $cols = [$startCol, $endCol];
        } elseif ($currentRow === $startRow) {
            $cols = [$startCol, PHP_INT_MAX];
        } elseif ($currentRow === $endRow) {
            $cols = [0, $endCol];
        } else {
            $cols = [0, PHP_INT_MAX];
        }

        return [
            max($cols[0], 0),
            min($cols[1], $this->rowMax($currentRow)),
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->buffer[$offset]);
    }

    #[ReturnTypeWillChange]
    public function offsetGet(mixed $offset)
    {
        return $this->buffer[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $offset = count($this->buffer);
            $this->buffer[] = $value;
        } else {
            $this->buffer[$offset] = $value;
        }

        $this->markLineDirty($offset);
        $this->trim();
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->buffer[$offset]);
    }
}
