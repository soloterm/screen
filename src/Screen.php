<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen;

use Closure;
use Exception;
use SoloTerm\Screen\Buffers\AnsiBuffer;
use SoloTerm\Screen\Buffers\Buffer;
use SoloTerm\Screen\Buffers\CellBuffer;
use SoloTerm\Screen\Buffers\PrintableBuffer;
use SoloTerm\Screen\Buffers\Proxy;
use Stringable;

class Screen
{
    public AnsiBuffer $ansi;

    public PrintableBuffer $printable;

    public Proxy $buffers;

    public int $cursorRow = 0;

    public int $cursorCol = 0;

    public int $linesOffScreen = 0;

    public int $width;

    public int $height;

    protected ?Closure $respondVia = null;

    protected array $stashedCursor = [];

    /**
     * Monotonically increasing sequence number. Incremented on each buffer modification.
     * Used for differential rendering to track which lines have changed.
     */
    protected int $seqNo = 0;

    /**
     * The sequence number at which output() was last called.
     * Used to determine which lines need re-rendering.
     */
    protected int $lastRenderedSeqNo = 0;

    public function __construct(int $width, int $height)
    {
        $this->width = $width;
        $this->height = $height;

        $this->ansi = new AnsiBuffer;
        $this->printable = (new PrintableBuffer)->setWidth($width);
        $this->buffers = new Proxy([
            $this->ansi,
            $this->printable
        ]);

        // Wire up sequence number provider to both buffers
        $seqNoProvider = fn() => ++$this->seqNo;
        $this->ansi->setSeqNoProvider($seqNoProvider);
        $this->printable->setSeqNoProvider($seqNoProvider);
    }

    /**
     * Get the current sequence number.
     */
    public function getSeqNo(): int
    {
        return $this->seqNo;
    }

    /**
     * Get the sequence number at which output() was last called.
     */
    public function getLastRenderedSeqNo(): int
    {
        return $this->lastRenderedSeqNo;
    }

    public function respondToQueriesVia(Closure $closure): static
    {
        $this->respondVia = $closure;

        return $this;
    }

    /**
     * Generate output string from the screen buffer.
     *
     * When $sinceSeqNo is null (default), outputs all lines joined by newlines.
     * This is the original behavior for full screen rendering.
     *
     * When $sinceSeqNo is provided, only outputs lines that have changed since
     * that sequence number, with cursor positioning for each changed line.
     * This enables differential rendering for significant performance gains.
     *
     * @param int|null $sinceSeqNo Only render lines changed after this sequence number
     * @return string The rendered output
     */
    public function output(?int $sinceSeqNo = null): string
    {
        // Update last rendered sequence number
        $this->lastRenderedSeqNo = $this->seqNo;

        // Differential rendering mode - optimized path
        if ($sinceSeqNo !== null) {
            return $this->outputDifferential($sinceSeqNo);
        }

        // Full rendering mode (original behavior)
        $ansi = $this->ansi->compressedAnsiBuffer();
        $printable = $this->printable->getBuffer();

        return $this->outputFull($ansi, $printable);
    }

    /**
     * Render all lines, joined by newlines. Original output behavior.
     */
    protected function outputFull(array $ansi, array $printable): string
    {
        $outputLines = [];

        foreach ($printable as $lineIndex => $line) {
            $outputLines[] = $this->renderLine($lineIndex, $line, $ansi[$lineIndex] ?? []);
        }

        return implode(PHP_EOL, $outputLines);
    }

    /**
     * Render only lines that changed since the given sequence number.
     * Each line is prefixed with a cursor positioning escape sequence.
     *
     * This method is optimized to only process changed rows, avoiding
     * the O(rows × cols) cost of processing the entire buffer.
     */
    protected function outputDifferential(int $sinceSeqNo): string
    {
        // Get changed rows from the printable buffer (which tracks all writes)
        $changedRows = $this->printable->getChangedRows($sinceSeqNo);

        // Early return if nothing changed
        if (empty($changedRows)) {
            return '';
        }

        $parts = [];
        $printable = $this->printable->getBuffer();

        // Only compute ANSI for changed rows
        foreach ($changedRows as $lineIndex) {
            // Skip rows that don't exist in the buffer
            if (!isset($printable[$lineIndex])) {
                continue;
            }

            // Calculate visible row (1-based, accounting for scroll offset)
            $visibleRow = $lineIndex - $this->linesOffScreen + 1;

            // Skip rows that are scrolled off screen
            if ($visibleRow < 1 || $visibleRow > $this->height) {
                continue;
            }

            $line = $printable[$lineIndex];

            // Compute compressed ANSI only for this specific line
            $ansiForLine = $this->compressAnsiForLine($lineIndex);

            // Position cursor at start of this line, then render
            $parts[] = "\033[{$visibleRow};1H";
            $parts[] = $this->renderLine($lineIndex, $line, $ansiForLine);
            // Clear to end of line to handle shortened content
            $parts[] = "\033[K";
        }

        return implode('', $parts);
    }

    /**
     * Compute compressed ANSI codes for a single line.
     * This is an optimized version of compressedAnsiBuffer() for one row.
     */
    protected function compressAnsiForLine(int $lineIndex): array
    {
        $line = $this->ansi->buffer[$lineIndex] ?? [];

        if (empty($line)) {
            return [];
        }

        // Reset on each line for safety (in case previous lines aren't visible)
        $previousCell = [0, null, null];

        return array_filter(array_map(function ($cell) use (&$previousCell) {
            if (is_int($cell)) {
                $cell = [$cell, null, null];
            }

            $uniqueBits = $cell[0] & ~$previousCell[0];
            $turnedOffBits = $previousCell[0] & ~$cell[0];

            $resetCodes = [];
            $turnedOffCodes = $this->ansi->ansiCodesFromBits($turnedOffBits);

            foreach ($turnedOffCodes as $code) {
                if ($code >= 30 && $code <= 39 || $code >= 90 && $code <= 97) {
                    $resetCodes[] = 39;
                } elseif ($code >= 40 && $code <= 49 || $code >= 100 && $code <= 107) {
                    $resetCodes[] = 49;
                } elseif ($code >= 1 && $code <= 9) {
                    // Map decoration codes to their resets
                    $decorationResets = [1 => 22, 2 => 22, 3 => 23, 4 => 24, 5 => 25, 7 => 27, 8 => 28, 9 => 29];
                    if (isset($decorationResets[$code])) {
                        $resetCodes[] = $decorationResets[$code];
                    }
                }
            }

            $uniqueCodes = $this->ansi->ansiCodesFromBits($uniqueBits);

            // Extended foreground changed
            if ($previousCell[1] !== $cell[1]) {
                if ($previousCell[1] !== null && $cell[1] === null) {
                    $resetCodes[] = 39;
                } elseif ($cell[1] !== null) {
                    $uniqueCodes[] = implode(';', [38, ...$cell[1]]);
                }
            }

            // Extended background changed
            if ($previousCell[2] !== $cell[2]) {
                if ($previousCell[2] !== null && $cell[2] === null) {
                    $resetCodes[] = 49;
                } elseif ($cell[2] !== null) {
                    $uniqueCodes[] = implode(';', [48, ...$cell[2]]);
                }
            }

            $previousCell = $cell;

            $allCodes = array_unique(array_merge($resetCodes, $uniqueCodes));

            return count($allCodes) ? ("\e[" . implode(';', $allCodes) . 'm') : '';
        }, $line));
    }

    /**
     * Render a single line by merging printable characters with ANSI codes.
     */
    protected function renderLine(int $lineIndex, array $line, array $ansiForLine): string
    {
        $lineStr = '';

        for ($col = 0; $col < count($line); $col++) {
            $lineStr .= ($ansiForLine[$col] ?? '') . $line[$col];
        }

        return $lineStr;
    }

    public function write(string $content): static
    {
        // Backspace character gets replaced with "move one column backwards."
        // Carriage returns get replaced with a code to move to column 0.
        $content = str_replace(
            search: ["\x08", "\r"],
            replace: ["\e[D", "\e[G"],
            subject: $content
        );

        // Split the line by ANSI codes using the fast state machine parser.
        // Each item in the resulting array will be a set of printable characters
        // or a ParsedAnsi object.
        $parts = AnsiParser::parseFast($content);

        foreach ($parts as $part) {
            if ($part instanceof Stringable) {
                // ParsedAnsi or AnsiMatch object
                if ($part->command) {
                    $this->handleAnsiCode($part);
                }
            } else {
                if ($part === '') {
                    continue;
                }

                $lines = explode(PHP_EOL, $part);
                $linesCount = count($lines);

                foreach ($lines as $index => $line) {
                    $this->handlePrintableCharacters($line);

                    if ($index < $linesCount - 1) {
                        $this->newlineWithScroll();
                    }
                }
            }
        }

        return $this;
    }

    public function writeln(string $content): void
    {
        if ($this->cursorCol === 0) {
            $this->write("$content\n");
        } else {
            $this->write("\n$content\n");
        }
    }

    /**
     * Handle an ANSI escape code.
     *
     * @param AnsiMatch|ParsedAnsi $ansi The parsed ANSI sequence
     */
    protected function handleAnsiCode(AnsiMatch|ParsedAnsi $ansi)
    {
        $command = $ansi->command;
        $param = $ansi->params;

        // Some commands have a default of zero and some have a default of one. Just
        // make both options and decide within the body of the if statement.
        // We could do a match here but it doesn't seem worth it.
        $paramDefaultZero = ($param !== '' && is_numeric($param)) ? intval($param) : 0;
        $paramDefaultOne = ($param !== '' && is_numeric($param)) ? intval($param) : 1;

        if ($command === 'A') {
            // Cursor up
            $this->moveCursorRow(relative: -$paramDefaultOne);

        } elseif ($command === 'B') {
            // Cursor down
            $this->moveCursorRow(relative: $paramDefaultOne);

        } elseif ($command === 'C') {
            // Cursor forward
            $this->moveCursorCol(relative: $paramDefaultOne);

        } elseif ($command === 'D') {
            // Cursor backward
            $this->moveCursorCol(relative: -$paramDefaultOne);

        } elseif ($command === 'E') {
            // Cursor to beginning of line, a number of lines down
            $this->moveCursorRow(relative: $paramDefaultOne);
            $this->moveCursorCol(absolute: 0);

        } elseif ($command === 'F') {
            // Cursor to beginning of line, a number of lines up
            $this->moveCursorRow(relative: -$paramDefaultOne);
            $this->moveCursorCol(absolute: 0);

        } elseif ($command === 'G') {
            // Cursor to column #, accounting for one-based indexing.
            $this->moveCursorCol($paramDefaultOne - 1);

        } elseif ($command === 'H') {
            $this->handleAbsoluteMove($ansi->params);

        } elseif ($command === 'I') {
            $this->handleTabulationMove($paramDefaultOne);

        } elseif ($command === 'J') {
            $this->handleEraseDisplay($paramDefaultZero);

        } elseif ($command === 'K') {
            $this->handleEraseInLine($paramDefaultZero);

        } elseif ($command === 'L') {
            $this->handleInsertLines($paramDefaultOne);

        } elseif ($command === 'S') {
            $this->handleScrollUp($paramDefaultOne);

        } elseif ($command === 'T') {
            $this->handleScrollDown($paramDefaultOne);

        } elseif ($command === 'l' || $command === 'h') {
            // Show/hide cursor. We simply ignore these.

        } elseif ($command === 'm') {
            // Colors / graphics mode
            $this->handleSGR($param);

        } elseif ($command === '7') {
            $this->saveCursor();

        } elseif ($command === '8') {
            $this->restoreCursor();

        } elseif ($param === '?' && in_array($command, ['10', '11'])) {
            // Ask for the foreground or background color.
            $this->handleQueryCode($command, $param);

        } elseif ($command === 'n' && $param === '6') {
            // Ask for the cursor position.
            $this->handleQueryCode($command, $param);
        }

        // @TODO Unhandled ansi command. Throw an error? Log it?
    }

    protected function newlineWithScroll()
    {
        if (($this->cursorRow - $this->linesOffScreen) >= $this->height - 1) {
            $this->linesOffScreen++;
        }

        $this->moveCursorRow(relative: 1);
        $this->moveCursorCol(absolute: 0);
    }

    protected function handlePrintableCharacters(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->printable->expand($this->cursorRow);

        [$advance, $remainder] = $this->printable->writeString($this->cursorRow, $this->cursorCol, $text);

        $this->ansi->fillBufferWithActiveFlags($this->cursorRow, $this->cursorCol, $this->cursorCol + $advance - 1);

        $this->cursorCol += $advance;

        // If there's overflow (i.e. text that didn't fit on this line),
        // move to a new line and recursively handle it.
        if ($remainder !== '') {
            $this->newlineWithScroll();
            $this->handlePrintableCharacters($remainder);
        }
    }

    public function saveCursor()
    {
        $this->stashedCursor = [
            $this->cursorCol,
            $this->cursorRow - $this->linesOffScreen
        ];
    }

    public function restoreCursor()
    {
        if ($this->stashedCursor) {
            [$col, $row] = $this->stashedCursor;
            $this->moveCursorCol(absolute: $col);
            $this->moveCursorRow(absolute: $row);
            $this->stashedCursor = [];
        }
    }

    public function moveCursorCol(?int $absolute = null, ?int $relative = null)
    {
        $this->ensureCursorParams($absolute, $relative);

        // Inside this method, position is zero-based.

        $max = $this->width;
        $min = 0;

        $position = $this->cursorCol;

        if (!is_null($absolute)) {
            $position = $absolute;
        }

        if (!is_null($relative)) {
            // Relative movements cannot put the cursor at the very end, only absolute
            // movements can. Not sure why, but I verified the behavior manually.
            $max -= 1;
            $position += $relative;
        }

        $position = min($position, $max);
        $position = max($min, $position);

        $this->cursorCol = $position;
    }

    public function moveCursorRow(?int $absolute = null, ?int $relative = null)
    {
        $this->ensureCursorParams($absolute, $relative);

        $max = $this->height + $this->linesOffScreen - 1;
        $min = $this->linesOffScreen;

        $position = $this->cursorRow;

        if (!is_null($absolute)) {
            $position = $absolute + $this->linesOffScreen;
        }

        if (!is_null($relative)) {
            $position += $relative;
        }

        $position = min($position, $max);
        $position = max($min, $position);

        $this->cursorRow = $position;

        $this->printable->expand($this->cursorRow);
    }

    protected function moveCursor(string $direction, ?int $absolute = null, ?int $relative = null): void
    {
        $this->ensureCursorParams($absolute, $relative);

        $property = $direction === 'x' ? 'cursorCol' : 'cursorRow';
        $max = $direction === 'x' ? $this->width : ($this->height + $this->linesOffScreen);
        $min = $direction === 'x' ? 0 : $this->linesOffScreen;

        if (!is_null($absolute)) {
            $this->{$property} = $absolute;
        }

        if (!is_null($relative)) {
            $this->{$property} += $relative;
        }

        $this->{$property} = min(
            max($this->{$property}, $min),
            $max - 1
        );
    }

    protected function ensureCursorParams($absolute, $relative): void
    {
        if (!is_null($absolute) && !is_null($relative)) {
            throw new Exception('Use either relative or absolute, but not both.');
        }

        if (is_null($absolute) && is_null($relative)) {
            throw new Exception('Relative and absolute cannot both be blank.');
        }
    }

    /**
     * Handle SGR (Select Graphic Rendition) ANSI codes for colors and styles.
     */
    protected function handleSGR(string $params): void
    {
        // Support multiple codes, like \e[30;41m
        $codes = array_map(intval(...), explode(';', $params));

        $this->ansi->addAnsiCodes(...$codes);
    }

    protected function handleTabulationMove(int $tabs)
    {
        $tabStop = 8;

        // If current column isn't at a tab stop, move to the next one.
        $remainder = $this->cursorCol % $tabStop;
        if ($remainder !== 0) {
            $this->cursorCol += ($tabStop - $remainder);
            $tabs--; // one tab stop consumed
        }

        // For any remaining tabs, move by full tab stops.
        if ($tabs > 0) {
            $this->cursorCol += $tabs * $tabStop;
        }
    }

    protected function handleAbsoluteMove(string $params)
    {
        if ($params !== '') {
            [$row, $col] = explode(';', $params);
            $row = $row === '' ? 1 : intval($row);
            $col = $col === '' ? 1 : intval($col);
        } else {
            $row = 1;
            $col = 1;
        }

        // ANSI codes are 1-based, while our system is 0-based.
        $this->moveCursorRow(absolute: --$row);
        $this->moveCursorCol(absolute: --$col);
    }

    protected function handleEraseDisplay(int $param): void
    {
        if ($param === 0) {
            // \e[0J - Erase from cursor until end of screen
            $this->buffers->clear(
                startRow: $this->cursorRow,
                startCol: $this->cursorCol
            );
        } elseif ($param === 1) {
            // \e[1J - Erase from cursor until beginning of screen
            $this->buffers->clear(
                startRow: $this->linesOffScreen,
                endRow: $this->cursorRow,
                endCol: $this->cursorCol
            );
        } elseif ($param === 2) {
            // \e[2J - Erase entire screen
            $this->buffers->clear(
                startRow: $this->linesOffScreen,
                endRow: $this->linesOffScreen + $this->height,
            );
        }
    }

    protected function handleInsertLines(int $lines): void
    {
        $allowed = $this->height - ($this->cursorRow - $this->linesOffScreen);
        $afterCursor = $lines + count($this->printable->buffer) - $this->cursorRow;

        $chop = $afterCursor - $allowed;

        // Ensure the buffer has enough rows so that $this->cursorRow is defined.
        if (!isset($this->printable->buffer[$this->cursorRow])) {
            $this->printable->expand($this->cursorRow);
        }

        if (!isset($this->ansi->buffer[$this->cursorRow])) {
            $this->ansi->expand($this->cursorRow);
        }

        // Create an array of $lines empty arrays.
        $newLines = array_fill(0, $lines, []);

        // Insert the new lines at the cursor row index.
        // array_splice will insert these new arrays and push the existing rows down.
        array_splice($this->printable->buffer, $this->cursorRow, 0, $newLines);
        array_splice($this->ansi->buffer, $this->cursorRow, 0, $newLines);

        if ($chop > 0) {
            array_splice($this->printable->buffer, -$chop);
            array_splice($this->ansi->buffer, -$chop);
        }

        // Mark all visible rows as dirty since insert/scroll affects them all
        $this->markVisibleRowsDirty();
    }

    /**
     * Mark all rows in the visible area as dirty.
     * Used after scroll/insert operations that shift content.
     */
    protected function markVisibleRowsDirty(): void
    {
        $startRow = $this->linesOffScreen;
        $endRow = $this->linesOffScreen + $this->height;

        for ($row = $startRow; $row < $endRow; $row++) {
            $this->printable->markLineDirty($row);
        }
    }

    protected function handleScrollDown(int $param): void
    {
        $stash = $this->cursorRow;

        $this->cursorRow = $this->linesOffScreen;

        $this->handleInsertLines($param);

        $this->cursorRow = $stash;
    }

    protected function handleScrollUp(int $param): void
    {
        $stash = $this->cursorRow;

        $this->printable->expand($this->height);

        $this->cursorRow = count($this->printable->buffer) + $param - 1;

        $this->handleInsertLines($param);

        $this->linesOffScreen += $param;

        $this->cursorRow = $stash + $param;
    }

    protected function handleEraseInLine(int $param): void
    {
        if ($param === 0) {
            // \e[0K - Erase from cursor to end of line
            $this->buffers->clear(
                startRow: $this->cursorRow,
                startCol: $this->cursorCol,
                endRow: $this->cursorRow
            );

            $background = $this->ansi->getActiveBackground();

            if ($background !== 0) {
                $this->printable->fill(' ', $this->cursorRow, $this->cursorCol, $this->width - 1);
                $this->ansi->fill($background, $this->cursorRow, $this->cursorCol, $this->width - 1);
            }
        } elseif ($param == 1) {
            // \e[1K - Erase start of line to the cursor
            $this->buffers->clear(
                startRow: $this->cursorRow,
                endRow: $this->cursorRow,
                endCol: $this->cursorCol
            );
        } elseif ($param === 2) {
            // \e[2K - Erase the entire line
            $this->buffers->clear(
                startRow: $this->cursorRow,
                endRow: $this->cursorRow
            );
        }
    }

    protected function handleQueryCode(string $command, string $param): void
    {
        if (!is_callable($this->respondVia)) {
            return;
        }

        $response = match ($param . $command) {
            // Foreground color
            // @TODO not hardcode this, somehow
            '?10' => "\e]10;rgb:0000/0000/0000 \e \\",
            // Background
            '?11' => "\e]11;rgb:FFFF/FFFF/FFFF \e \\",
            // Cursor
            '6n' => "\e[" . ($this->cursorRow + 1) . ';' . ($this->cursorCol + 1) . 'R',
            default => null,
        };

        if ($response) {
            call_user_func($this->respondVia, $response);
        }
    }

    /**
     * Convert the visible portion of the screen to a CellBuffer.
     *
     * This enables value-based comparison between frames for
     * differential rendering, comparing actual cell content
     * rather than just tracking which cells were written.
     *
     * @param CellBuffer|null $targetBuffer Optional existing buffer to write into
     * @return CellBuffer The buffer containing the visible screen content
     */
    public function toCellBuffer(?CellBuffer $targetBuffer = null): CellBuffer
    {
        $buffer = $targetBuffer ?? new CellBuffer($this->width, $this->height);
        $printable = $this->printable->getBuffer();

        // Only convert the visible portion of the screen
        for ($row = 0; $row < $this->height; $row++) {
            $bufferRow = $row + $this->linesOffScreen;

            $printableLine = $printable[$bufferRow] ?? [];
            $ansiLine = $this->ansi->buffer[$bufferRow] ?? [];

            for ($col = 0; $col < $this->width; $col++) {
                $char = $printableLine[$col] ?? ' ';

                // Get raw ANSI cell data
                $ansiCell = $ansiLine[$col] ?? 0;

                // Parse the ANSI cell
                if (is_int($ansiCell)) {
                    $bits = $ansiCell;
                    $extFg = null;
                    $extBg = null;
                } else {
                    $bits = $ansiCell[0] ?? 0;
                    $extFg = $ansiCell[1] ?? null;
                    $extBg = $ansiCell[2] ?? null;
                }

                // Convert to Cell - extract style, fg, bg from the bitmask
                [$style, $fg, $bg] = $this->extractStyleFromBits($bits);

                $cell = new Cell($char, $style, $fg, $bg, $extFg, $extBg);
                $buffer->setCell($row, $col, $cell);
            }
        }

        return $buffer;
    }

    /**
     * Extract Cell-compatible style, foreground, and background from AnsiBuffer bitmask.
     *
     * @param int $bits The AnsiBuffer bitmask
     * @return array{int, int|null, int|null} [style, fg, bg]
     */
    protected function extractStyleFromBits(int $bits): array
    {
        // The AnsiBuffer assigns bits to codes in the order they're added to $supported:
        // 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 22-29, 30-39, 40-49, 90-97, 100-107
        // Each gets a sequential bit (1, 2, 4, 8, 16, ...)

        $style = 0;
        $fg = null;
        $bg = null;

        // Build the code-to-bit mapping (matching AnsiBuffer::initialize)
        $supported = [
            0, // bit 0 (value 1)
            1, 2, 3, 4, 5, 6, 7, 8, 9, // bits 1-9 (values 2, 4, 8, ...)
            22, 23, 24, 25, 26, 27, 28, 29, // decoration resets
            30, 31, 32, 33, 34, 35, 36, 37, 38, 39, // foreground
            40, 41, 42, 43, 44, 45, 46, 47, 48, 49, // background
            90, 91, 92, 93, 94, 95, 96, 97, // bright foreground
            100, 101, 102, 103, 104, 105, 106, 107, // bright background
        ];

        $codesBits = [];
        foreach ($supported as $i => $code) {
            $codesBits[$code] = 1 << $i;
        }

        // Extract decoration style (codes 1-9 -> Cell style bits 0-8)
        for ($code = 1; $code <= 9; $code++) {
            if (isset($codesBits[$code]) && ($bits & $codesBits[$code])) {
                $style |= (1 << ($code - 1));
            }
        }

        // Extract foreground color (30-37, 90-97)
        foreach ([...range(30, 37), ...range(90, 97)] as $code) {
            if (isset($codesBits[$code]) && ($bits & $codesBits[$code])) {
                $fg = $code;
                break;
            }
        }

        // Extract background color (40-47, 100-107)
        foreach ([...range(40, 47), ...range(100, 107)] as $code) {
            if (isset($codesBits[$code]) && ($bits & $codesBits[$code])) {
                $bg = $code;
                break;
            }
        }

        return [$style, $fg, $bg];
    }
}
