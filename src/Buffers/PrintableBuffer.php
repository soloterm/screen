<?php

namespace SoloTerm\Screen\Buffers;

use Exception;
use SoloTerm\Grapheme\Grapheme;

class PrintableBuffer extends Buffer
{
    public int $width;

    protected mixed $valueForClearing = ' ';

    protected bool $trackDirtySpanHistory = true;

    public function setWidth(int $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function resizeWidth(int $width): static
    {
        $oldWidth = $this->width;

        if ($width < $oldWidth) {
            foreach ($this->buffer as $row => $line) {
                $normalized = array_replace(array_fill(0, $oldWidth, ' '), $line);
                $visible = array_slice($normalized, 0, $width);

                for ($col = 0; $col < $width; $col++) {
                    if (array_key_exists($col, $normalized) && $normalized[$col] === null) {
                        continue;
                    }

                    $endCol = $col;
                    while (
                        ($endCol + 1) < $oldWidth
                        && array_key_exists($endCol + 1, $normalized)
                        && $normalized[$endCol + 1] === null
                    ) {
                        $endCol++;
                    }

                    if ($endCol >= $width) {
                        for ($fill = $col; $fill < $width; $fill++) {
                            $visible[$fill] = ' ';
                        }
                    }

                    $col = $endCol;
                }

                while ($visible !== [] && end($visible) === ' ') {
                    array_pop($visible);
                }

                if ($visible !== $line) {
                    $this->buffer[$row] = $visible;
                    $this->markLineDirty($row);
                }
            }
        } else {
            parent::resizeWidth($width);
        }

        $this->width = $width;

        return $this;
    }

    /**
     * Writes a string into the buffer at the specified row and starting column.
     * The string is split into "units" (either single characters or grapheme clusters),
     * and each unit is inserted into one or more cells based on its display width.
     * If a unit has width > 1, its first cell gets the unit, and the remaining cells are set to PHP null.
     *
     * If the text overflows the available width on that row, the function stops writing and returns
     * an array containing the number of columns advanced and a string of the remaining characters.
     *
     * @param  int  $row  Row index (0-based).
     * @param  int  $col  Starting column index (0-based).
     * @param  string  $text  The text to write.
     * @return array [$advanceCursor, $remainder]
     *
     * @throws Exception if splitting into graphemes fails.
     */
    public function writeString(int $row, int $col, string $text): array
    {
        if (!isset($this->buffer[$row])) {
            $this->buffer[$row] = [];
        }

        $line = &$this->buffer[$row];
        $dirtyStart = null;
        $dirtyEnd = null;

        $this->fillSparsePrefix($line, $col, $dirtyStart, $dirtyEnd);
        $this->clearOverwrittenWideCharacter($line, $col, $dirtyStart, $dirtyEnd);

        if (strlen($text) === mb_strlen($text)) {
            [$advanceCursor, $remainder] = $this->writeAsciiString($line, $col, $text, $dirtyStart, $dirtyEnd);
        } else {
            [$advanceCursor, $remainder] = $this->writeMultibyteString($line, $col, $text, $dirtyStart, $dirtyEnd);
        }

        if ($dirtyStart !== null && $dirtyEnd !== null) {
            $this->markLineDirty($row, $dirtyStart, $dirtyEnd);
        }

        return [$advanceCursor, $remainder];
    }

    protected function fillSparsePrefix(array &$line, int $col, ?int &$dirtyStart, ?int &$dirtyEnd): void
    {
        $lineCount = count($line);

        if ($lineCount < $col) {
            for ($i = $lineCount; $i < $col; $i++) {
                $line[$i] = ' ';
            }

            $this->touchDirtySpan($dirtyStart, $dirtyEnd, $lineCount, $col - 1);

            return;
        }

        for ($i = 0; $i < $col; $i++) {
            if (!array_key_exists($i, $line)) {
                $line[$i] = ' ';
                $this->touchDirtySpan($dirtyStart, $dirtyEnd, $i, $i);
            }
        }
    }

    protected function clearOverwrittenWideCharacter(
        array &$line,
        int $col,
        ?int &$dirtyStart,
        ?int &$dirtyEnd
    ): void {
        if (!array_key_exists($col, $line) || $line[$col] !== null) {
            return;
        }

        $spanStart = $col;

        for ($i = $col; $i >= 0; $i--) {
            if (!isset($line[$i]) || $line[$i] === null) {
                $line[$i] = ' ';
                $spanStart = $i;
            } else {
                $line[$i] = ' ';
                $spanStart = $i;

                break;
            }
        }

        $this->touchDirtySpan($dirtyStart, $dirtyEnd, $spanStart, $col);
    }

    /**
     * @return array{int, string}
     */
    protected function writeAsciiString(
        array &$line,
        int $col,
        string $text,
        ?int &$dirtyStart,
        ?int &$dirtyEnd
    ): array {
        $currentCol = $col;
        $advanceCursor = 0;
        $length = strlen($text);
        $offset = 0;

        for ($offset = 0; $offset < $length; $offset++) {
            $unit = $text[$offset];
            $unitWidth = $unit === "\t" ? (8 - ($currentCol % 8)) : 1;

            if ($currentCol + $unitWidth > $this->width) {
                break;
            }

            $line[$currentCol] = $unit;
            $this->touchDirtySpan($dirtyStart, $dirtyEnd, $currentCol, $currentCol + $unitWidth - 1);

            for ($j = 1; $j < $unitWidth; $j++) {
                $line[$currentCol + $j] = null;
            }

            $currentCol += $unitWidth;
            $this->clearTrailingContinuations($line, $currentCol, $dirtyStart, $dirtyEnd);
            $advanceCursor += $unitWidth;
        }

        return [$advanceCursor, $offset < $length ? substr($text, $offset) : ''];
    }

    /**
     * @return array{int, string}
     *
     * @throws Exception if splitting into grapheme clusters fails.
     */
    protected function writeMultibyteString(
        array &$line,
        int $col,
        string $text,
        ?int &$dirtyStart,
        ?int &$dirtyEnd
    ): array {
        $units = $this->splitPrintableUnits($text);
        $currentCol = $col;
        $advanceCursor = 0;
        $totalUnits = count($units);
        $i = 0;

        for ($i = 0; $i < $totalUnits; $i++) {
            $unit = $units[$i];
            $unitWidth = $unit === "\t" ? (8 - ($currentCol % 8)) : Grapheme::wcwidth($unit);

            if ($currentCol + $unitWidth > $this->width) {
                break;
            }

            $line[$currentCol] = $unit;
            $this->touchDirtySpan($dirtyStart, $dirtyEnd, $currentCol, $currentCol + $unitWidth - 1);

            for ($j = 1; $j < $unitWidth; $j++) {
                if (($currentCol + $j) < $this->width) {
                    $line[$currentCol + $j] = null;
                }
            }

            $currentCol += $unitWidth;
            $this->clearTrailingContinuations($line, $currentCol, $dirtyStart, $dirtyEnd);
            $advanceCursor += $unitWidth;
        }

        return [$advanceCursor, implode('', array_slice($units, $i))];
    }

    protected function clearTrailingContinuations(
        array &$line,
        int $currentCol,
        ?int &$dirtyStart,
        ?int &$dirtyEnd
    ): void {
        if (!array_key_exists($currentCol, $line) || $line[$currentCol] !== null) {
            return;
        }

        $k = $currentCol;

        while (array_key_exists($k, $line) && $line[$k] === null) {
            $line[$k] = ' ';
            $k++;
        }

        $this->touchDirtySpan($dirtyStart, $dirtyEnd, $currentCol, $k - 1);
    }

    protected function touchDirtySpan(?int &$dirtyStart, ?int &$dirtyEnd, int $startCol, int $endCol): void
    {
        if ($endCol < $startCol) {
            return;
        }

        $dirtyStart = $dirtyStart === null ? $startCol : min($dirtyStart, $startCol);
        $dirtyEnd = $dirtyEnd === null ? $endCol : max($dirtyEnd, $endCol);
    }

    /**
     * Split a printable string into terminal write units.
     *
     * Screen intentionally keeps this separate from Grapheme::split():
     * the renderer follows terminal-parity behavior for overwrite/movement,
     * while Grapheme::split() follows the best available Unicode default
     * segmentation rules.
     *
     * @return list<string>
     *
     * @throws Exception if splitting into grapheme clusters fails.
     */
    protected function splitPrintableUnits(string $text): array
    {
        if (strlen($text) === mb_strlen($text)) {
            return str_split($text);
        }

        if (preg_match_all('/\X/u', $text, $matches) === false) {
            throw new Exception('Error splitting text into grapheme clusters.');
        }

        return $matches[0];
    }

    public function lines(): array
    {
        return array_map(fn($line) => implode('', $line), $this->buffer);
    }
}
