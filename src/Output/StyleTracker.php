<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Output;

use SoloTerm\Screen\Cell;

/**
 * Tracks current terminal style state to minimize style change sequences.
 *
 * Instead of always emitting full SGR sequences, this class:
 * - Tracks the current style state
 * - Only emits codes for attributes that actually changed
 * - Uses efficient reset strategies when attributes are removed
 */
class StyleTracker
{
    /**
     * Current style bitmask (bold, italic, underline, etc.)
     */
    protected int $style = 0;

    /**
     * Current foreground color (basic ANSI: 30-37, 90-97)
     */
    protected ?int $fg = null;

    /**
     * Current background color (basic ANSI: 40-47, 100-107)
     */
    protected ?int $bg = null;

    /**
     * Current extended foreground color [type, ...params]
     */
    protected ?array $extFg = null;

    /**
     * Current extended background color [type, ...params]
     */
    protected ?array $extBg = null;

    /**
     * Reset style tracking to default state.
     */
    public function reset(): void
    {
        $this->style = 0;
        $this->fg = null;
        $this->bg = null;
        $this->extFg = null;
        $this->extBg = null;
    }

    /**
     * Check if we have any active styling.
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
     * Generate the escape sequence to transition to the target cell's style.
     *
     * @param Cell $cell The target cell with desired style
     * @return string The escape sequence (empty if no change needed)
     */
    public function transitionTo(Cell $cell): string
    {
        // Check if any change is needed
        if ($this->style === $cell->style
            && $this->fg === $cell->fg
            && $this->bg === $cell->bg
            && $this->extFg === $cell->extFg
            && $this->extBg === $cell->extBg) {
            return '';
        }

        $codes = [];

        // Check if we need to reset (some styles were turned off or color type changed)
        $turnedOff = $this->style & ~$cell->style;
        $fgTypeChanged = ($this->extFg !== null && $cell->extFg === null)
            || ($this->fg !== null && $cell->extFg !== null);
        $bgTypeChanged = ($this->extBg !== null && $cell->extBg === null)
            || ($this->bg !== null && $cell->extBg !== null);
        $needsReset = $turnedOff !== 0
            || ($this->fg !== null && $cell->fg === null && $cell->extFg === null)
            || ($this->bg !== null && $cell->bg === null && $cell->extBg === null)
            || $fgTypeChanged
            || $bgTypeChanged;

        if ($needsReset) {
            // Reset and re-apply all current styles
            $codes[] = '0';
            $this->style = 0;
            $this->fg = null;
            $this->bg = null;
            $this->extFg = null;
            $this->extBg = null;

            // Add all target styles
            $codes = array_merge($codes, $this->getStyleCodes($cell->style));

            if ($cell->extFg !== null) {
                $codes[] = '38;' . implode(';', $cell->extFg);
            } elseif ($cell->fg !== null) {
                $codes[] = (string) $cell->fg;
            }

            if ($cell->extBg !== null) {
                $codes[] = '48;' . implode(';', $cell->extBg);
            } elseif ($cell->bg !== null) {
                $codes[] = (string) $cell->bg;
            }
        } else {
            // Incremental update - only add new styles

            // Add new style attributes
            $newStyles = $cell->style & ~$this->style;
            if ($newStyles !== 0) {
                $codes = array_merge($codes, $this->getStyleCodes($newStyles));
            }

            // Handle foreground color
            if ($cell->fg !== $this->fg || $cell->extFg !== $this->extFg) {
                if ($cell->extFg !== null) {
                    $codes[] = '38;' . implode(';', $cell->extFg);
                } elseif ($cell->fg !== null) {
                    $codes[] = (string) $cell->fg;
                }
            }

            // Handle background color
            if ($cell->bg !== $this->bg || $cell->extBg !== $this->extBg) {
                if ($cell->extBg !== null) {
                    $codes[] = '48;' . implode(';', $cell->extBg);
                } elseif ($cell->bg !== null) {
                    $codes[] = (string) $cell->bg;
                }
            }
        }

        // Update tracked state
        $this->style = $cell->style;
        $this->fg = $cell->fg;
        $this->bg = $cell->bg;
        $this->extFg = $cell->extFg;
        $this->extBg = $cell->extBg;

        if (empty($codes)) {
            return '';
        }

        return "\e[" . implode(';', $codes) . "m";
    }

    /**
     * Generate a reset sequence if we have any active styles.
     *
     * @return string ESC[0m if styles are active, empty otherwise
     */
    public function resetIfNeeded(): string
    {
        if ($this->hasStyle()) {
            $this->reset();
            return "\e[0m";
        }

        return '';
    }

    /**
     * Convert a style bitmask to an array of ANSI code strings.
     *
     * @param int $bitmask The style bitmask
     * @return array<string> Array of ANSI code strings
     */
    protected function getStyleCodes(int $bitmask): array
    {
        $codes = [];

        // Map bit positions to ANSI codes (1-9: bold, dim, italic, underline, blink, etc.)
        for ($code = 1; $code <= 9; $code++) {
            if ($bitmask & (1 << ($code - 1))) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }
}
