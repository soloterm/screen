<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Output;

/**
 * Optimizes cursor movement sequences to minimize output bytes.
 *
 * Instead of always using absolute positioning (ESC[row;colH),
 * this class chooses the most efficient movement strategy:
 * - Carriage return (\r) for moving to column 0
 * - Newline (\n) for moving down one row
 * - Relative movements (ESC[nA/B/C/D) when cheaper
 * - Absolute positioning when it's the shortest option
 */
class CursorOptimizer
{
    protected int $currentRow = 0;

    protected int $currentCol = 0;

    /**
     * Reset cursor tracking to origin.
     */
    public function reset(): void
    {
        $this->currentRow = 0;
        $this->currentCol = 0;
    }

    /**
     * Get the current tracked cursor position.
     *
     * @return array{row: int, col: int}
     */
    public function getPosition(): array
    {
        return [
            'row' => $this->currentRow,
            'col' => $this->currentCol,
        ];
    }

    /**
     * Generate the optimal escape sequence to move cursor to target position.
     *
     * @param  int  $row  Target row (0-indexed)
     * @param  int  $col  Target column (0-indexed)
     * @return string The escape sequence (may be empty if already at position)
     */
    public function moveTo(int $row, int $col): string
    {
        // Already at target position
        if ($row === $this->currentRow && $col === $this->currentCol) {
            return '';
        }

        // Home position: ESC[H (3 bytes)
        if ($row === 0 && $col === 0) {
            $this->currentRow = 0;
            $this->currentCol = 0;

            return "\e[H";
        }

        // Same row, column 0: carriage return (1 byte)
        if ($row === $this->currentRow && $col === 0) {
            $this->currentCol = 0;

            return "\r";
        }

        // Down one row, same column: newline (1 byte) - only if at col 0 or we handle it
        if ($row === $this->currentRow + 1 && $col === 0 && $this->currentCol === 0) {
            $this->currentRow++;

            return "\n";
        }

        // Calculate costs for different strategies
        $dRow = $row - $this->currentRow;
        $dCol = $col - $this->currentCol;

        $relativeCost = $this->calculateRelativeCost($dRow, $dCol);
        $absoluteCost = $this->calculateAbsoluteCost($row, $col);

        // Also consider: CR + relative vertical + relative horizontal
        $crBasedCost = PHP_INT_MAX;
        if ($col < $this->currentCol || $dRow !== 0) {
            // Cost of CR + vertical move + horizontal move from col 0
            $crBasedCost = 1 + $this->calculateVerticalCost($dRow) + $this->calculateHorizontalCost($col);
        }

        $this->currentRow = $row;
        $this->currentCol = $col;

        // Choose the cheapest option
        if ($relativeCost <= $absoluteCost && $relativeCost <= $crBasedCost) {
            return $this->buildRelativeMove($dRow, $dCol);
        } elseif ($crBasedCost < $absoluteCost) {
            return $this->buildCrBasedMove($dRow, $col);
        } else {
            return $this->buildAbsoluteMove($row, $col);
        }
    }

    /**
     * Move cursor right by the width of a character just written.
     * Call this after outputting a character to keep tracking accurate.
     *
     * @param  int  $width  Character width (1 for normal, 2 for wide chars)
     */
    public function advance(int $width = 1): void
    {
        $this->currentCol += $width;
    }

    /**
     * Calculate the byte cost of relative cursor movement.
     */
    protected function calculateRelativeCost(int $dRow, int $dCol): int
    {
        return $this->calculateVerticalCost($dRow) + $this->calculateHorizontalCost(abs($dCol));
    }

    /**
     * Calculate the byte cost of vertical movement.
     */
    protected function calculateVerticalCost(int $dRow): int
    {
        if ($dRow === 0) {
            return 0;
        }

        $n = abs($dRow);

        // ESC[A or ESC[B for n=1: 3 bytes
        // ESC[nA or ESC[nB for n>1: 3 + digits
        if ($n === 1) {
            return 3;
        }

        return 3 + strlen((string) $n);
    }

    /**
     * Calculate the byte cost of horizontal movement.
     */
    protected function calculateHorizontalCost(int $distance): int
    {
        if ($distance === 0) {
            return 0;
        }

        // ESC[C or ESC[D for n=1: 3 bytes
        // ESC[nC or ESC[nD for n>1: 3 + digits
        if ($distance === 1) {
            return 3;
        }

        return 3 + strlen((string) $distance);
    }

    /**
     * Calculate the byte cost of absolute positioning.
     */
    protected function calculateAbsoluteCost(int $row, int $col): int
    {
        // ESC[row;colH
        // Minimum: ESC[1;1H = 6 bytes
        // row and col are 1-indexed in ANSI
        return 4 + strlen((string) ($row + 1)) + strlen((string) ($col + 1));
    }

    /**
     * Build the relative movement escape sequence.
     */
    protected function buildRelativeMove(int $dRow, int $dCol): string
    {
        $result = '';

        // Vertical movement
        if ($dRow !== 0) {
            $n = abs($dRow);
            $dir = $dRow > 0 ? 'B' : 'A';
            $result .= $n === 1 ? "\e[{$dir}" : "\e[{$n}{$dir}";
        }

        // Horizontal movement
        if ($dCol !== 0) {
            $n = abs($dCol);
            $dir = $dCol > 0 ? 'C' : 'D';
            $result .= $n === 1 ? "\e[{$dir}" : "\e[{$n}{$dir}";
        }

        return $result;
    }

    /**
     * Build a CR-based movement (carriage return + relative moves).
     */
    protected function buildCrBasedMove(int $dRow, int $targetCol): string
    {
        $result = "\r";

        // Vertical movement
        if ($dRow !== 0) {
            $n = abs($dRow);
            $dir = $dRow > 0 ? 'B' : 'A';
            $result .= $n === 1 ? "\e[{$dir}" : "\e[{$n}{$dir}";
        }

        // Horizontal movement from column 0
        if ($targetCol > 0) {
            $result .= $targetCol === 1 ? "\e[C" : "\e[{$targetCol}C";
        }

        return $result;
    }

    /**
     * Build an absolute positioning escape sequence.
     */
    protected function buildAbsoluteMove(int $row, int $col): string
    {
        // ANSI is 1-indexed
        return "\e[" . ($row + 1) . ';' . ($col + 1) . 'H';
    }
}
