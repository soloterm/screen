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
use InvalidArgumentException;
use SoloTerm\Grapheme\Grapheme;
use SoloTerm\Screen\Buffers\AnsiBuffer;
use SoloTerm\Screen\Buffers\Buffer;
use SoloTerm\Screen\Buffers\CellBuffer;
use SoloTerm\Screen\Buffers\PrintableBuffer;
use SoloTerm\Screen\Buffers\Proxy;
use Stringable;
use WeakMap;

class Screen
{
    protected const DEC_SPECIAL_GRAPHICS_MAP = [
        '`' => '◆',
        'a' => '▒',
        'f' => '°',
        'g' => '±',
        'h' => '␤',
        'i' => '␋',
        'j' => '┘',
        'k' => '┐',
        'l' => '┌',
        'm' => '└',
        'n' => '┼',
        'o' => '⎺',
        'p' => '⎻',
        'q' => '─',
        'r' => '⎼',
        's' => '⎽',
        't' => '├',
        'u' => '┤',
        'v' => '┴',
        'w' => '┬',
        'x' => '│',
        'y' => '≤',
        'z' => '≥',
        '{' => 'π',
        '|' => '≠',
        '}' => '£',
        '~' => '·',
    ];

    protected const ANSI_DECORATION_RESETS = [
        1 => 22,
        2 => 22,
        3 => 23,
        4 => 24,
        5 => 25,
        7 => 27,
        8 => 28,
        9 => 29,
    ];

    protected static ?array $ansiCodeBits = null;

    protected static array $styleExtractionCache = [
        0 => [0, null, null],
    ];

    protected static array $differentialAnsiCodesCache = [
        0 => [],
    ];

    protected static array $differentialAnsiResetCodesCache = [
        0 => [],
    ];

    public AnsiBuffer $ansi;

    public PrintableBuffer $printable;

    public Proxy $buffers;

    public int $cursorRow = 0;

    public int $cursorCol = 0;

    public int $linesOffScreen = 0;

    public int $width;

    public int $height;

    protected ?Closure $respondVia = null;

    protected ?Closure $reportUnhandledVia = null;

    protected array $stashedCursor = [];

    protected bool $decSpecialGraphicsEnabled = false;

    protected ?array $mainScreenState = null;

    protected bool $alternateScreenActive = false;

    protected Closure $seqNoProvider;

    protected string $pendingAnsi = '';

    protected string $pendingUtf8 = '';

    protected array $pendingClearedRows = [];

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

    /**
     * Tracks the last viewport mapping written into each reusable CellBuffer.
     */
    protected WeakMap $cellBufferViewportState;

    public function __construct(int $width, int $height)
    {
        $this->width = $width;
        $this->height = $height;
        $this->cellBufferViewportState = new WeakMap;

        $this->seqNoProvider = fn() => ++$this->seqNo;
        $this->initializeBuffers();
    }

    protected function initializeBuffers(): void
    {
        $this->ansi = new AnsiBuffer;
        $this->printable = (new PrintableBuffer)->setWidth($this->width);
        $this->buffers = new Proxy([
            $this->ansi,
            $this->printable
        ]);

        $this->ansi->setSeqNoProvider($this->seqNoProvider);
        $this->printable->setSeqNoProvider($this->seqNoProvider);
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

    public function reportUnhandledSequencesVia(Closure $closure): static
    {
        $this->reportUnhandledVia = $closure;

        return $this;
    }

    public function resize(int $width, int $height): static
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Screen dimensions must be at least 1x1.');
        }

        $oldHeight = $this->height;
        $this->width = $width;
        $this->height = $height;

        $this->printable->resizeWidth($width);
        $this->ansi->resizeWidth($width);
        $this->clampViewportToVisibleContent();
        $this->markVisibleRowsDirty();

        if ($height < $oldHeight) {
            $this->pendingClearedRows = array_values(array_unique([
                ...$this->pendingClearedRows,
                ...range($height + 1, $oldHeight),
            ]));
            sort($this->pendingClearedRows);
        }

        if ($this->mainScreenState !== null) {
            $this->mainScreenState = $this->resizeScreenState($this->mainScreenState, $width, $height);
        }

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
     * @param  int|null  $sinceSeqNo  Only render lines changed after this sequence number
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
     * Render all lines using relative cursor positioning.
     *
     * Uses DECSC/DECRC (save/restore cursor) combined with CUD (cursor down)
     * to position each line relative to where the caller placed the cursor.
     * This approach:
     * - Avoids "pending wrap" issues (different terminals handle full-width
     *   lines differently when using \n)
     * - Uses relative positioning so Screen output can be rendered at any
     *   offset in a parent TUI (unlike \r which always goes to column 1)
     * - Never uses \n between lines, so wrap semantics don't matter
     */
    protected function outputFull(array $ansi, array $printable): string
    {
        $parts = [];

        // Save the caller's current cursor position as the Screen origin.
        // DECSC (DEC Save Cursor) - ESC 7
        $parts[] = "\0337";

        foreach ($printable as $lineIndex => $line) {
            $visibleRow = $lineIndex - $this->linesOffScreen + 1;

            if ($visibleRow < 1 || $visibleRow > $this->height) {
                continue;
            }

            // Restore to origin (top-left of this Screen in the parent TUI).
            // DECRC (DEC Restore Cursor) - ESC 8
            $parts[] = "\0338";

            // Move down to this line's row (relative) from the origin.
            // visibleRow is 1-based, so visibleRow=1 is the origin row (no movement needed).
            if ($visibleRow > 1) {
                $parts[] = "\033[" . ($visibleRow - 1) . 'B'; // CUD (cursor down)
            }

            // Render the line content. No newline afterwards.
            $parts[] = $this->renderLine($lineIndex, $line, $ansi[$lineIndex] ?? []);
        }

        $parts[] = $this->renderPendingClearedRows(relativeToSavedCursor: true);

        return implode('', $parts);
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
        $changedRows = $this->changedRowsSince($sinceSeqNo);

        if (empty($changedRows) && empty($this->pendingClearedRows)) {
            return '';
        }

        $parts = [];
        $printable = $this->printable->getBuffer();

        foreach ($changedRows as $lineIndex) {
            $visibleRow = $lineIndex - $this->linesOffScreen + 1;

            if ($visibleRow < 1 || $visibleRow > $this->height) {
                continue;
            }

            $line = $printable[$lineIndex] ?? [];
            $span = $this->changedSpanForRow($lineIndex, $sinceSeqNo);

            if ($span === null) {
                continue;
            }

            [$startCol, $endCol] = $span;
            $startCol = $this->normalizeChangedColumnStart($line, $startCol);
            $clearToEndOfLine = $startCol === 0 || $endCol >= count($line);
            $renderEndCol = $clearToEndOfLine
                ? (count($line) - 1)
                : min($endCol, count($line) - 1);

            $parts[] = "\033[{$visibleRow};" . ($startCol + 1) . 'H';
            $parts[] = "\033[0m";
            $parts[] = $this->renderDifferentialLine($lineIndex, $line, $startCol, $renderEndCol);

            if ($clearToEndOfLine) {
                $parts[] = "\033[K";
            }
        }

        $parts[] = $this->renderPendingClearedRows();

        return implode('', $parts);
    }

    /**
     * Compute compressed ANSI codes for a single line.
     * This is an optimized version of compressedAnsiBuffer() for one row.
     */
    protected function renderDifferentialLine(int $lineIndex, array $line, int $startCol = 0, ?int $endCol = null): string
    {
        $ansiLine = $this->ansi->buffer[$lineIndex] ?? [];

        if ($line === [] || $endCol !== null && $endCol < $startCol) {
            return '';
        }

        $previousCell = [0, null, null];
        $lineStr = '';
        $lineLength = count($line);
        $lastCol = $endCol === null ? ($lineLength - 1) : min($endCol, $lineLength - 1);

        for ($col = $startCol; $col <= $lastCol; $col++) {
            $cell = $ansiLine[$col] ?? 0;

            if (is_int($cell)) {
                $cell = [$cell, null, null];
            }

            $lineStr .= $this->ansiTransitionForDifferentialLine($cell, $previousCell);
            $lineStr .= $line[$col] ?? ' ';
            $previousCell = $cell;
        }

        return $lineStr;
    }

    /**
     * @return array<int>
     */
    protected function changedRowsSince(int $sinceSeqNo): array
    {
        $changedRows = array_fill_keys($this->printable->getChangedRows($sinceSeqNo), true);

        foreach ($this->ansi->getChangedRows($sinceSeqNo) as $row) {
            $changedRows[$row] = true;
        }

        $rows = array_map('intval', array_keys($changedRows));
        sort($rows);

        return $rows;
    }

    /**
     * @return array{0:int,1:int}|null
     */
    protected function changedSpanForRow(int $row, int $sinceSeqNo): ?array
    {
        $printableSpan = $this->printable->getChangedSpan($row, $sinceSeqNo);
        $ansiSpan = $this->ansi->getChangedSpan($row, $sinceSeqNo);

        if ($printableSpan !== null) {
            return $printableSpan;
        }

        return $ansiSpan;
    }

    protected function normalizeChangedColumnStart(array $printableLine, int $startCol): int
    {
        $startCol = max(0, min($startCol, $this->width - 1));

        while ($startCol > 0 && (($printableLine[$startCol] ?? ' ') === null)) {
            $startCol--;
        }

        return $startCol;
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

    /**
     * Build the ANSI transition for a differential output cell.
     *
     * @param  array{0:int,1:?array,2:?array}  $cell
     * @param  array{0:int,1:?array,2:?array}  $previousCell
     */
    protected function ansiTransitionForDifferentialLine(array $cell, array $previousCell): string
    {
        if (
            $cell[0] === $previousCell[0]
            && $cell[1] === $previousCell[1]
            && $cell[2] === $previousCell[2]
        ) {
            return '';
        }

        $uniqueBits = $cell[0] & ~$previousCell[0];
        $turnedOffBits = $previousCell[0] & ~$cell[0];

        $resetCodes = $this->ansiResetCodesForDifferentialBits($turnedOffBits);
        $uniqueCodes = $this->ansiCodesForDifferentialBits($uniqueBits);

        if ($previousCell[1] !== $cell[1]) {
            if ($previousCell[1] !== null && $cell[1] === null) {
                $resetCodes[] = 39;
            } elseif ($cell[1] !== null) {
                $uniqueCodes[] = $this->buildDifferentialExtendedColorCode(38, $cell[1]);
            }
        }

        if ($previousCell[2] !== $cell[2]) {
            if ($previousCell[2] !== null && $cell[2] === null) {
                $resetCodes[] = 49;
            } elseif ($cell[2] !== null) {
                $uniqueCodes[] = $this->buildDifferentialExtendedColorCode(48, $cell[2]);
            }
        }

        $allCodes = array_unique(array_merge($resetCodes, $uniqueCodes));

        return $allCodes === [] ? '' : ("\e[" . implode(';', $allCodes) . 'm');
    }

    protected function ansiCodesForDifferentialBits(int $bits): array
    {
        if (isset(self::$differentialAnsiCodesCache[$bits])) {
            return self::$differentialAnsiCodesCache[$bits];
        }

        return self::$differentialAnsiCodesCache[$bits] = $this->ansi->ansiCodesFromBits($bits);
    }

    protected function ansiResetCodesForDifferentialBits(int $bits): array
    {
        if (isset(self::$differentialAnsiResetCodesCache[$bits])) {
            return self::$differentialAnsiResetCodesCache[$bits];
        }

        $resetCodes = [];

        foreach ($this->ansiCodesForDifferentialBits($bits) as $code) {
            if (($code >= 30 && $code <= 39) || ($code >= 90 && $code <= 97)) {
                $resetCodes[] = 39;
            } elseif (($code >= 40 && $code <= 49) || ($code >= 100 && $code <= 107)) {
                $resetCodes[] = 49;
            } elseif ($code >= 1 && $code <= 9 && isset(self::ANSI_DECORATION_RESETS[$code])) {
                $resetCodes[] = self::ANSI_DECORATION_RESETS[$code];
            }
        }

        return self::$differentialAnsiResetCodesCache[$bits] = array_values(array_unique($resetCodes));
    }

    protected function buildDifferentialExtendedColorCode(int $prefix, array $color): string
    {
        return $prefix . ';' . implode(';', $color);
    }

    public function write(string $content): static
    {
        $content = $this->pendingAnsi . $this->pendingUtf8 . $content;
        $this->pendingAnsi = '';
        $this->pendingUtf8 = '';

        // Backspace character gets replaced with "move one column backwards."
        // Carriage returns get replaced with a code to move to column 0.
        $content = str_replace(
            search: ["\x08", "\r"],
            replace: ["\e[D", "\e[G"],
            subject: $content
        );

        [$content, $this->pendingAnsi] = $this->splitTrailingIncompleteAnsi($content);

        if ($content === '') {
            return $this;
        }

        // Split the line by ANSI codes using the fast state machine parser.
        // Each item in the resulting array will be a set of printable characters
        // or a ParsedAnsi object.
        $parts = AnsiParser::parseFast($content);

        $partsCount = count($parts);

        foreach ($parts as $index => $part) {
            if ($part instanceof Stringable) {
                // ParsedAnsi or AnsiMatch object
                if ($part->command !== null) {
                    $this->handleAnsiCode($part);
                }
            } else {
                if ($part === '') {
                    continue;
                }

                if ($index === $partsCount - 1) {
                    [$part, $this->pendingUtf8] = $this->splitTrailingIncompleteUtf8($part);
                }

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

    /**
     * Split trailing incomplete ANSI sequence from the content so it can be
     * completed by a subsequent write() call.
     *
     * @return array{string, string}
     */
    protected function splitTrailingIncompleteAnsi(string $content): array
    {
        $lastEsc = strrpos($content, "\x1B");

        if ($lastEsc === false) {
            return [$content, ''];
        }

        $tail = substr($content, $lastEsc);

        if (!$this->trailingAnsiSequenceIsIncomplete($tail)) {
            return [$content, ''];
        }

        return [substr($content, 0, $lastEsc), $tail];
    }

    protected function trailingAnsiSequenceIsIncomplete(string $tail): bool
    {
        if ($tail === '' || $tail[0] !== "\x1B") {
            return false;
        }

        if (strlen($tail) === 1) {
            return true;
        }

        $next = $tail[1];

        if ($next === '[') {
            $i = 2;
            $len = strlen($tail);

            while ($i < $len) {
                $ord = ord($tail[$i]);

                if ($ord >= 0x30 && $ord <= 0x3F) {
                    $i++;

                    continue;
                }

                if ($ord >= 0x20 && $ord <= 0x2F) {
                    $i++;

                    continue;
                }

                return !($ord >= 0x40 && $ord <= 0x7E);
            }

            return true;
        }

        if ($next === ']') {
            $i = 2;
            $len = strlen($tail);

            while ($i < $len) {
                if ($tail[$i] === "\x07" || $tail[$i] === "\x9C") {
                    return false;
                }

                if ($tail[$i] === "\x1B" && $i + 1 < $len && $tail[$i + 1] === '\\') {
                    return false;
                }

                $i++;
            }

            return true;
        }

        if ($next === '(' || $next === ')' || $next === '#') {
            return strlen($tail) < 3;
        }

        return false;
    }

    /**
     * Split a trailing incomplete UTF-8 byte sequence from a printable chunk so
     * it can be completed by a subsequent write() call.
     *
     * @return array{string, string}
     */
    protected function splitTrailingIncompleteUtf8(string $content): array
    {
        if ($content === '' || mb_check_encoding($content, 'UTF-8')) {
            return [$content, ''];
        }

        $maxTailLength = min(3, strlen($content));

        for ($tailLength = 1; $tailLength <= $maxTailLength; $tailLength++) {
            $prefix = substr($content, 0, -$tailLength);

            if ($prefix === '' || mb_check_encoding($prefix, 'UTF-8')) {
                return [$prefix, substr($content, -$tailLength)];
            }
        }

        return ['', $content];
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
     * @param  AnsiMatch|ParsedAnsi  $ansi  The parsed ANSI sequence
     */
    protected function handleAnsiCode(AnsiMatch|ParsedAnsi $ansi)
    {
        $command = $ansi->command;
        $param = $ansi->params;

        if ($command === null) {
            return;
        }

        if (($command === '(' || $command === ')') && $this->handleCharsetSelection($ansi)) {
            return;
        }

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

        } elseif ($command === 'M') {
            $this->handleReverseIndex();

        } elseif ($command === '@') {
            $this->handleInsertCharacters(max(1, $paramDefaultOne));

        } elseif ($command === 'J') {
            $this->handleEraseDisplay($paramDefaultZero);

        } elseif ($command === 'K') {
            $this->handleEraseInLine($paramDefaultZero);

        } elseif ($command === 'L') {
            $this->handleInsertLines($paramDefaultOne);

        } elseif ($command === 'P') {
            $this->handleDeleteCharacters(max(1, $paramDefaultOne));

        } elseif ($command === 'S') {
            $this->handleScrollUp($paramDefaultOne);

        } elseif ($command === 'T') {
            $this->handleScrollDown($paramDefaultOne);

        } elseif ($command === 'X') {
            $this->handleEraseCharacters(max(1, $paramDefaultOne));

        } elseif ($param === '?1049' && $command === 'h') {
            $this->enterAlternateScreen();

        } elseif ($param === '?1049' && $command === 'l') {
            $this->exitAlternateScreen();

        } elseif (($param === '?25' || $param === '25' || $param === '') && ($command === 'l' || $command === 'h')) {
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

        } else {
            $this->reportUnhandledSequence($ansi);
        }
    }

    protected function handleCharsetSelection(AnsiMatch|ParsedAnsi $ansi): bool
    {
        if (!($ansi instanceof ParsedAnsi) || strlen($ansi->raw) < 3) {
            return false;
        }

        if ($ansi->command !== '(') {
            return false;
        }

        $this->decSpecialGraphicsEnabled = $ansi->raw[2] === '0';

        return true;
    }

    protected function handleReverseIndex(): void
    {
        if ($this->cursorRow === $this->linesOffScreen) {
            $this->handleScrollDown(1);

            return;
        }

        $this->moveCursorRow(relative: -1);
    }

    protected function enterAlternateScreen(): void
    {
        if ($this->alternateScreenActive) {
            return;
        }

        $this->mainScreenState = [
            'ansi' => clone $this->ansi,
            'printable' => clone $this->printable,
            'cursorRow' => $this->cursorRow,
            'cursorCol' => $this->cursorCol,
            'linesOffScreen' => $this->linesOffScreen,
            'stashedCursor' => $this->stashedCursor,
            'decSpecialGraphicsEnabled' => $this->decSpecialGraphicsEnabled,
        ];

        $this->initializeBuffers();
        $this->cursorRow = 0;
        $this->cursorCol = 0;
        $this->linesOffScreen = 0;
        $this->stashedCursor = [];
        $this->decSpecialGraphicsEnabled = false;
        $this->alternateScreenActive = true;
    }

    protected function exitAlternateScreen(): void
    {
        if (!$this->alternateScreenActive || $this->mainScreenState === null) {
            return;
        }

        $this->ansi = $this->mainScreenState['ansi'];
        $this->printable = $this->mainScreenState['printable'];
        $this->buffers = new Proxy([
            $this->ansi,
            $this->printable,
        ]);
        $this->cursorRow = $this->mainScreenState['cursorRow'];
        $this->cursorCol = $this->mainScreenState['cursorCol'];
        $this->linesOffScreen = $this->mainScreenState['linesOffScreen'];
        $this->stashedCursor = $this->mainScreenState['stashedCursor'];
        $this->decSpecialGraphicsEnabled = $this->mainScreenState['decSpecialGraphicsEnabled'];
        $this->mainScreenState = null;
        $this->alternateScreenActive = false;
        $this->markVisibleRowsDirty();
    }

    protected function newlineWithScroll()
    {
        if (($this->cursorRow - $this->linesOffScreen) >= $this->height - 1) {
            $this->linesOffScreen++;
            // Mark all visible rows dirty since their visual positions changed
            $this->markVisibleRowsDirty();
        }

        $this->moveCursorRow(relative: 1);
        $this->moveCursorCol(absolute: 0);
    }

    protected function handlePrintableCharacters(string $text): void
    {
        if ($text === '') {
            return;
        }

        $text = $this->mergeLeadingGraphemeExtensions($text);

        if ($text === '') {
            return;
        }

        if ($this->decSpecialGraphicsEnabled) {
            $text = strtr($text, self::DEC_SPECIAL_GRAPHICS_MAP);
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

    protected function handleDeleteCharacters(int $count): void
    {
        $row = $this->cursorRow;
        $blankAnsi = $this->ansi->getBackgroundEraseCellValue();
        $printable = $this->normalizedRow($this->printable->buffer[$row] ?? [], ' ');
        $ansi = $this->normalizedRow($this->ansi->buffer[$row] ?? [], 0);

        array_splice($printable, $this->cursorCol, $count);
        array_splice($ansi, $this->cursorCol, $count);

        $printable = array_slice(array_pad($printable, $this->width, ' '), 0, $this->width);
        $ansi = array_slice(array_pad($ansi, $this->width, $blankAnsi), 0, $this->width);

        [$printable, $ansi] = $this->trimTrailingCells($printable, $ansi);

        $this->printable[$row] = $printable;
        $this->ansi[$row] = $ansi;
    }

    protected function handleInsertCharacters(int $count): void
    {
        $row = $this->cursorRow;
        $blankAnsi = $this->ansi->getBackgroundEraseCellValue();
        $printable = $this->normalizedRow($this->printable->buffer[$row] ?? [], ' ');
        $ansi = $this->normalizedRow($this->ansi->buffer[$row] ?? [], 0);

        array_splice($printable, $this->cursorCol, 0, array_fill(0, $count, ' '));
        array_splice($ansi, $this->cursorCol, 0, array_fill(0, $count, $blankAnsi));

        $printable = array_slice($printable, 0, $this->width);
        $ansi = array_slice($ansi, 0, $this->width);

        [$printable, $ansi] = $this->trimTrailingCells($printable, $ansi);

        $this->printable[$row] = $printable;
        $this->ansi[$row] = $ansi;
    }

    protected function handleEraseCharacters(int $count): void
    {
        $row = $this->cursorRow;
        $endCol = min($this->width - 1, $this->cursorCol + $count - 1);
        $blankAnsi = $this->ansi->getBackgroundEraseCellValue();
        $printable = $this->normalizedRow($this->printable->buffer[$row] ?? [], ' ');
        $ansi = $this->normalizedRow($this->ansi->buffer[$row] ?? [], 0);

        for ($col = $this->cursorCol; $col <= $endCol; $col++) {
            $printable[$col] = ' ';
            $ansi[$col] = $blankAnsi;
        }

        [$printable, $ansi] = $this->trimTrailingCells($printable, $ansi);

        $this->printable[$row] = $printable;
        $this->ansi[$row] = $ansi;
    }

    protected function normalizedRow(array $row, mixed $default): array
    {
        return array_replace(array_fill(0, $this->width, $default), $row);
    }

    protected function trimTrailingDefaultValues(array $row, mixed $default): array
    {
        while ($row !== [] && end($row) === $default) {
            array_pop($row);
        }

        return $row;
    }

    protected function trimTrailingCells(array $printable, array $ansi): array
    {
        $lastIndex = max(count($printable), count($ansi)) - 1;

        while ($lastIndex >= 0) {
            $printableCell = $printable[$lastIndex] ?? ' ';
            $ansiCell = $ansi[$lastIndex] ?? 0;

            if ($printableCell !== ' ' || $ansiCell !== 0) {
                break;
            }

            $lastIndex--;
        }

        if ($lastIndex < 0) {
            return [[], []];
        }

        return [
            array_slice($printable, 0, $lastIndex + 1),
            array_slice($ansi, 0, $lastIndex + 1),
        ];
    }

    public function saveCursor()
    {
        $this->stashedCursor = [
            'col' => $this->cursorCol,
            'row' => $this->cursorRow - $this->linesOffScreen,
            'ansiState' => $this->ansi->exportState(),
            'decSpecialGraphicsEnabled' => $this->decSpecialGraphicsEnabled,
        ];
    }

    public function restoreCursor()
    {
        if ($this->stashedCursor) {
            $col = $this->stashedCursor['col'] ?? $this->stashedCursor[0] ?? 0;
            $row = $this->stashedCursor['row'] ?? $this->stashedCursor[1] ?? 0;
            $this->moveCursorCol(absolute: $col);
            $this->moveCursorRow(absolute: $row);

            if (isset($this->stashedCursor['ansiState'])) {
                $this->ansi->importState($this->stashedCursor['ansiState']);
            }

            if (array_key_exists('decSpecialGraphicsEnabled', $this->stashedCursor)) {
                $this->decSpecialGraphicsEnabled = $this->stashedCursor['decSpecialGraphicsEnabled'];
            }
        }
    }

    protected function mergeLeadingGraphemeExtensions(string $text): string
    {
        if ($this->cursorCol === 0 || $text === '') {
            return $text;
        }

        if (!preg_match('/\A((?:\p{M}|[\x{FE00}-\x{FE0F}]|[\x{1F3FB}-\x{1F3FF}])+)(.*)\z/us', $text, $matches)) {
            return $text;
        }

        $extension = $matches[1];
        $remainder = $matches[2];
        $row = $this->cursorRow;

        if (!isset($this->printable->buffer[$row])) {
            return $text;
        }

        $previousCol = $this->cursorCol - 1;
        while (
            $previousCol >= 0
            && array_key_exists($previousCol, $this->printable->buffer[$row])
            && $this->printable->buffer[$row][$previousCol] === null
        ) {
            $previousCol--;
        }

        if ($previousCol < 0) {
            return $text;
        }

        $previous = $this->printable->buffer[$row][$previousCol] ?? ' ';
        if ($previous === ' ' || $previous === '') {
            return $text;
        }

        $combined = $previous . $extension;
        $oldWidth = max(0, Grapheme::wcwidth($previous));

        $this->printable->writeString($row, $previousCol, $combined);
        $newWidth = max(0, Grapheme::wcwidth($combined));
        $fillWidth = max($oldWidth, $newWidth);

        if ($fillWidth > 0) {
            $this->ansi->fillBufferWithActiveFlags($row, $previousCol, $previousCol + $fillWidth - 1);
        }

        $this->cursorCol = $previousCol + $newWidth;

        return $remainder;
    }

    public function moveCursorCol(?int $absolute = null, ?int $relative = null)
    {
        $this->ensureCursorParams($absolute, $relative);

        // Inside this method, position is zero-based.

        $max = $this->width - 1;
        $min = 0;

        $position = $this->cursorCol;

        if (!is_null($absolute)) {
            $position = $absolute;
        }

        if (!is_null($relative)) {
            // Relative movements cannot put the cursor at the very end, only absolute
            // movements can. Not sure why, but I verified the behavior manually.
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
            [$row, $col] = array_pad(explode(';', $params, 2), 2, '');
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

    protected function clampViewportToVisibleContent(): void
    {
        $totalRows = max(count($this->printable->buffer), $this->cursorRow + 1);
        $maxLinesOffScreen = max(0, $totalRows - $this->height);

        $this->linesOffScreen = min(max($this->linesOffScreen, 0), $maxLinesOffScreen);
        $this->cursorCol = min(max($this->cursorCol, 0), $this->width);

        $minVisibleRow = $this->linesOffScreen;
        $maxVisibleRow = $this->linesOffScreen + $this->height - 1;
        $this->cursorRow = min(max($this->cursorRow, $minVisibleRow), $maxVisibleRow);
    }

    protected function resizeScreenState(array $state, int $width, int $height): array
    {
        $state['printable']->resizeWidth($width);
        $state['ansi']->resizeWidth($width);

        $totalRows = max(count($state['printable']->buffer), ($state['cursorRow'] ?? 0) + 1);
        $maxLinesOffScreen = max(0, $totalRows - $height);

        $state['linesOffScreen'] = min(max($state['linesOffScreen'] ?? 0, 0), $maxLinesOffScreen);
        $state['cursorCol'] = min(max($state['cursorCol'] ?? 0, 0), $width);

        $minVisibleRow = $state['linesOffScreen'];
        $maxVisibleRow = $state['linesOffScreen'] + $height - 1;
        $state['cursorRow'] = min(max($state['cursorRow'] ?? 0, $minVisibleRow), $maxVisibleRow);

        return $state;
    }

    protected function renderPendingClearedRows(bool $relativeToSavedCursor = false): string
    {
        if ($this->pendingClearedRows === []) {
            return '';
        }

        $parts = [];

        foreach ($this->pendingClearedRows as $row) {
            if ($relativeToSavedCursor) {
                $parts[] = "\0338";
                if ($row > 1) {
                    $parts[] = "\033[" . ($row - 1) . 'B';
                }
                $parts[] = "\033[K";
            } else {
                $parts[] = "\033[{$row};1H\033[K";
            }
        }

        $this->pendingClearedRows = [];

        return implode('', $parts);
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
                $this->ansi->fill($this->ansi->getBackgroundEraseCellValue(), $this->cursorRow, $this->cursorCol, $this->width - 1);
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
            '?10' => "\e]10;rgb:0000/0000/0000\e\\",
            // Background
            '?11' => "\e]11;rgb:FFFF/FFFF/FFFF\e\\",
            // Cursor
            '6n' => "\e[" . ($this->cursorRow + 1) . ';' . ($this->cursorCol + 1) . 'R',
            default => null,
        };

        if ($response) {
            call_user_func($this->respondVia, $response);
        }
    }

    protected function reportUnhandledSequence(AnsiMatch|ParsedAnsi $ansi): void
    {
        if (!is_callable($this->reportUnhandledVia)) {
            return;
        }

        call_user_func($this->reportUnhandledVia, (string) $ansi);
    }

    /**
     * Convert the visible portion of the screen to a CellBuffer.
     *
     * This enables value-based comparison between frames for
     * differential rendering, comparing actual cell content
     * rather than just tracking which cells were written.
     *
     * @param  CellBuffer|null  $targetBuffer  Optional existing buffer to write into
     * @return CellBuffer The buffer containing the visible screen content
     */
    public function toCellBuffer(?CellBuffer $targetBuffer = null): CellBuffer
    {
        $buffer = $targetBuffer ?? new CellBuffer($this->width, $this->height);

        $forceFullSync = false;

        if ($buffer->getWidth() !== $this->width || $buffer->getHeight() !== $this->height) {
            $buffer->resetDimensions($this->width, $this->height);
            $forceFullSync = true;
        }

        $viewportState = [
            'linesOffScreen' => $this->linesOffScreen,
            'width' => $this->width,
            'height' => $this->height,
            'printableId' => spl_object_id($this->printable),
            'ansiId' => spl_object_id($this->ansi),
        ];

        if (!$forceFullSync) {
            $previousViewportState = isset($this->cellBufferViewportState[$buffer])
                ? $this->cellBufferViewportState[$buffer]
                : null;

            $forceFullSync = $previousViewportState !== $viewportState;
        }

        $currentSeqNo = $this->getSeqNo();
        $printable = $this->printable->getBuffer();

        for ($row = 0; $row < $this->height; $row++) {
            $sourceRow = $row + $this->linesOffScreen;
            $startCol = 0;
            $endCol = $this->width - 1;

            if (!$forceFullSync) {
                $rowSeqNo = $buffer->getLineSeqNo($row);
                $span = $this->changedSpanForRow($sourceRow, $rowSeqNo);

                if ($span === null) {
                    continue;
                }

                $printableLine = $printable[$sourceRow] ?? [];
                $startCol = $this->normalizeChangedColumnStart($printableLine, $span[0]);
                $endCol = min($this->width - 1, $span[1]);
            } else {
                $printableLine = $printable[$sourceRow] ?? [];
            }

            $this->materializeCellBufferRow(
                buffer: $buffer,
                targetRow: $row,
                printableLine: $printableLine,
                ansiLine: $this->ansi->buffer[$sourceRow] ?? [],
                startCol: $startCol,
                endCol: $endCol,
            );

            $buffer->setLineSeqNo($row, $currentSeqNo);
        }

        $this->cellBufferViewportState[$buffer] = $viewportState;

        return $buffer;
    }

    /**
     * Materialize a single visible row into the target CellBuffer.
     */
    protected function materializeCellBufferRow(
        CellBuffer $buffer,
        int $targetRow,
        array $printableLine,
        array $ansiLine,
        int $startCol = 0,
        ?int $endCol = null
    ): void {
        $lastCol = $endCol === null ? ($this->width - 1) : min($endCol, $this->width - 1);

        for ($col = $startCol; $col <= $lastCol; $col++) {
            $char = array_key_exists($col, $printableLine)
                ? $printableLine[$col]
                : ' ';
            $existing = $buffer->getCell($targetRow, $col);
            $ansiCell = $ansiLine[$col] ?? 0;

            // Blank/default cells are the common case, so avoid style decoding and
            // object allocation entirely when the current cell already matches.
            if (is_int($ansiCell) && $ansiCell === 0) {
                if ($char === null) {
                    if ($existing->isContinuation() && !$existing->hasStyle()) {
                        continue;
                    }

                    $cell = Cell::continuation();
                } else {
                    if ($existing->char === $char && !$existing->hasStyle()) {
                        continue;
                    }

                    $cell = new Cell($char);
                }

                $buffer->setCell($targetRow, $col, $cell);

                continue;
            }

            if (is_int($ansiCell)) {
                $bits = $ansiCell;
                $extFg = null;
                $extBg = null;
            } else {
                $bits = $ansiCell[0] ?? 0;
                $extFg = $ansiCell[1] ?? null;
                $extBg = $ansiCell[2] ?? null;
            }

            [$style, $fg, $bg] = $this->extractStyleFromBits($bits);

            if (
                $char !== null
                && $existing->char === $char
                && $existing->style === $style
                && $existing->fg === $fg
                && $existing->bg === $bg
                && $existing->extFg === $extFg
                && $existing->extBg === $extBg
            ) {
                continue;
            }

            if (
                $char === null
                && $existing->isContinuation()
                && $existing->style === $style
                && $existing->fg === $fg
                && $existing->bg === $bg
                && $existing->extFg === $extFg
                && $existing->extBg === $extBg
            ) {
                continue;
            }

            $buffer->setCell(
                $targetRow,
                $col,
                $this->cellFromVisibleBuffers(
                    $char,
                    $ansiCell,
                    $style,
                    $fg,
                    $bg,
                    $extFg,
                    $extBg,
                )
            );
        }
    }

    protected function cellFromVisibleBuffers(
        ?string $char,
        int|array $ansiCell,
        ?int $style = null,
        ?int $fg = null,
        ?int $bg = null,
        ?array $extFg = null,
        ?array $extBg = null,
    ): Cell {
        if ($style === null) {
            if (is_int($ansiCell)) {
                $bits = $ansiCell;
                $extFg = null;
                $extBg = null;
            } else {
                $bits = $ansiCell[0] ?? 0;
                $extFg = $ansiCell[1] ?? null;
                $extBg = $ansiCell[2] ?? null;
            }

            [$style, $fg, $bg] = $this->extractStyleFromBits($bits);
        }

        if ($char === null) {
            $cell = Cell::continuation();
            $cell->style = $style;
            $cell->fg = $fg;
            $cell->bg = $bg;
            $cell->extFg = $extFg;
            $cell->extBg = $extBg;

            return $cell;
        }

        return new Cell($char, $style, $fg, $bg, $extFg, $extBg);
    }

    /**
     * Extract Cell-compatible style, foreground, and background from AnsiBuffer bitmask.
     *
     * @param  int  $bits  The AnsiBuffer bitmask
     * @return array{int, int|null, int|null} [style, fg, bg]
     */
    protected function extractStyleFromBits(int $bits): array
    {
        if (isset(self::$styleExtractionCache[$bits])) {
            return self::$styleExtractionCache[$bits];
        }

        $style = 0;
        $fg = null;
        $bg = null;
        $codesBits = self::ansiCodeBits();

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

        return self::$styleExtractionCache[$bits] = [$style, $fg, $bg];
    }

    protected static function ansiCodeBits(): array
    {
        if (self::$ansiCodeBits !== null) {
            return self::$ansiCodeBits;
        }

        $supported = [
            0,
            1, 2, 3, 4, 5, 6, 7, 8, 9,
            22, 23, 24, 25, 26, 27, 28, 29,
            30, 31, 32, 33, 34, 35, 36, 37, 38, 39,
            40, 41, 42, 43, 44, 45, 46, 47, 48, 49,
            90, 91, 92, 93, 94, 95, 96, 97,
            100, 101, 102, 103, 104, 105, 106, 107,
        ];

        self::$ansiCodeBits = [];

        foreach ($supported as $i => $code) {
            self::$ansiCodeBits[$code] = 1 << $i;
        }

        return self::$ansiCodeBits;
    }
}
