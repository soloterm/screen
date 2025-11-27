<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen;

/**
 * Represents a single cell in the terminal buffer.
 *
 * Combines the printable character with its ANSI styling attributes
 * into a single object for efficient comparison and rendering.
 */
class Cell
{
    /**
     * Create a new Cell.
     *
     * @param  string  $char  The printable character (or null for wide char continuation)
     * @param  int  $style  Bitmask of active ANSI decoration codes (bold, italic, etc.)
     * @param  int|null  $fg  Foreground color code (30-37, 90-97) or null for default
     * @param  int|null  $bg  Background color code (40-47, 100-107) or null for default
     * @param  array|null  $extFg  Extended foreground color [type, ...params] for 256/RGB colors
     * @param  array|null  $extBg  Extended background color [type, ...params] for 256/RGB colors
     */
    public function __construct(
        public string $char = ' ',
        public int $style = 0,
        public ?int $fg = null,
        public ?int $bg = null,
        public ?array $extFg = null,
        public ?array $extBg = null,
    ) {}

    /**
     * Check if this cell is visually equal to another cell.
     *
     * Used for differential rendering to detect changes.
     */
    public function equals(Cell $other): bool
    {
        return $this->char === $other->char
            && $this->style === $other->style
            && $this->fg === $other->fg
            && $this->bg === $other->bg
            && $this->extFg === $other->extFg
            && $this->extBg === $other->extBg;
    }

    /**
     * Create a blank cell (space with no styling).
     */
    public static function blank(): self
    {
        return new self(' ', 0, null, null, null, null);
    }

    /**
     * Create a continuation cell for wide characters.
     * These cells take up space but render as empty.
     */
    public static function continuation(): self
    {
        $cell = new self;
        $cell->char = '';

        return $cell;
    }

    /**
     * Check if this is a continuation cell (part of a wide character).
     */
    public function isContinuation(): bool
    {
        return $this->char === '';
    }

    /**
     * Check if this cell has any styling applied.
     */
    public function hasStyle(): bool
    {
        return $this->style !== 0
            || $this->fg !== null
            || $this->bg !== null
            || $this->extFg !== null
            || $this->extBg !== null;
    }

    /**
     * Clone this cell with a different character.
     */
    public function withChar(string $char): self
    {
        $cell = clone $this;
        $cell->char = $char;

        return $cell;
    }

    /**
     * Clone this cell with different styling.
     */
    public function withStyle(int $style, ?int $fg = null, ?int $bg = null, ?array $extFg = null, ?array $extBg = null): self
    {
        $cell = clone $this;
        $cell->style = $style;
        $cell->fg = $fg;
        $cell->bg = $bg;
        $cell->extFg = $extFg;
        $cell->extBg = $extBg;

        return $cell;
    }

    /**
     * Get the ANSI escape sequence to transition from another cell's style to this cell's style.
     *
     * @param  Cell|null  $previous  The previous cell's style (null = reset state)
     * @return string The ANSI escape sequence (empty string if no change needed)
     */
    public function getStyleTransition(?Cell $previous = null): string
    {
        // If no previous, we're at the start of a line - always emit full style
        if ($previous === null) {
            return $this->getFullStyleSequence();
        }

        // If styles are identical, no transition needed
        if ($this->style === $previous->style
            && $this->fg === $previous->fg
            && $this->bg === $previous->bg
            && $this->extFg === $previous->extFg
            && $this->extBg === $previous->extBg) {
            return '';
        }

        // Build transition codes
        $codes = [];

        // Check if we need to turn off any styles
        $turnedOff = $previous->style & ~$this->style;
        if ($turnedOff !== 0) {
            // For simplicity, if any style was turned off, we reset and re-apply
            // This could be optimized to use specific reset codes (22, 23, 24, etc.)
            return "\e[0m" . $this->getFullStyleSequence();
        }

        // Add any new style codes
        $newStyles = $this->style & ~$previous->style;
        if ($newStyles !== 0) {
            $codes = array_merge($codes, $this->getStyleCodesFromBitmask($newStyles));
        }

        // Handle foreground color changes
        if ($this->fg !== $previous->fg || $this->extFg !== $previous->extFg) {
            if ($this->extFg !== null) {
                $codes[] = '38;' . implode(';', $this->extFg);
            } elseif ($this->fg !== null) {
                $codes[] = (string) $this->fg;
            } elseif ($previous->fg !== null || $previous->extFg !== null) {
                $codes[] = '39'; // Reset foreground
            }
        }

        // Handle background color changes
        if ($this->bg !== $previous->bg || $this->extBg !== $previous->extBg) {
            if ($this->extBg !== null) {
                $codes[] = '48;' . implode(';', $this->extBg);
            } elseif ($this->bg !== null) {
                $codes[] = (string) $this->bg;
            } elseif ($previous->bg !== null || $previous->extBg !== null) {
                $codes[] = '49'; // Reset background
            }
        }

        if (empty($codes)) {
            return '';
        }

        return "\e[" . implode(';', $codes) . 'm';
    }

    /**
     * Get the full ANSI sequence to apply this cell's style from a reset state.
     */
    protected function getFullStyleSequence(): string
    {
        if (!$this->hasStyle()) {
            return '';
        }

        $codes = $this->getStyleCodesFromBitmask($this->style);

        if ($this->extFg !== null) {
            $codes[] = '38;' . implode(';', $this->extFg);
        } elseif ($this->fg !== null) {
            $codes[] = (string) $this->fg;
        }

        if ($this->extBg !== null) {
            $codes[] = '48;' . implode(';', $this->extBg);
        } elseif ($this->bg !== null) {
            $codes[] = (string) $this->bg;
        }

        if (empty($codes)) {
            return '';
        }

        return "\e[" . implode(';', $codes) . 'm';
    }

    /**
     * Convert a style bitmask to an array of ANSI code strings.
     */
    protected function getStyleCodesFromBitmask(int $bitmask): array
    {
        $codes = [];

        // Map bit positions to ANSI codes
        // These correspond to codes 1-9 (bold, dim, italic, underline, blink, etc.)
        for ($code = 1; $code <= 9; $code++) {
            if ($bitmask & (1 << ($code - 1))) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }
}
