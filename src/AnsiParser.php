<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen;

/**
 * State machine-based ANSI escape sequence parser.
 *
 * This parser processes input character-by-character without using regex,
 * which provides better performance for large inputs with many ANSI sequences.
 *
 * Based on the VT100/VT500 state machine, simplified for common sequences:
 * - CSI sequences: ESC [ params command (e.g., ESC[31m, ESC[2;1H)
 * - Simple escapes: ESC command (e.g., ESC 7, ESC 8)
 * - OSC sequences: ESC ] ... terminator (e.g., ESC]0;title BEL)
 */
class AnsiParser
{
    /**
     * Lookup table for simple escape commands (ESC + single char).
     * Using array for O(1) lookup instead of in_array().
     */
    protected static array $simpleEscapes = [
        '7' => true, '8' => true, 'c' => true, 'D' => true, 'E' => true,
        'H' => true, 'M' => true, 'N' => true, 'O' => true, 'Z' => true,
        '=' => true, '>' => true, '<' => true, '1' => true, '2' => true,
        's' => true, 'u' => true,
    ];

    /**
     * Parse input and return an array of tokens (strings and AnsiMatch objects).
     *
     * This method is a drop-in replacement for AnsiMatcher::split().
     * Optimized version with inlined checks for better performance.
     *
     * @param  string  $input  The input string to parse
     * @return array<string|AnsiMatch> Array of text strings and ANSI match objects
     */
    public static function parse(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $tokens = [];
        $textStart = 0;
        $len = strlen($input);
        $i = 0;

        while ($i < $len) {
            // Fast path: scan for ESC character
            $escPos = strpos($input, "\x1B", $i);

            if ($escPos === false) {
                // No more escape sequences - rest is text
                if ($i < $len) {
                    $tokens[] = substr($input, $textStart);
                }
                break;
            }

            // Add text before this escape sequence
            if ($escPos > $textStart) {
                $tokens[] = substr($input, $textStart, $escPos - $textStart);
            }

            // Parse the escape sequence
            $escStart = $escPos;
            $i = $escPos + 1;

            if ($i >= $len) {
                // Incomplete escape at end
                $tokens[] = "\x1B";
                break;
            }

            $nextChar = $input[$i];

            if ($nextChar === '[') {
                // CSI sequence: ESC [ params final
                $i++;
                $paramStart = $i;

                // Collect parameter bytes (0x30-0x3F: 0-9, ;, ?, <, =, >)
                while ($i < $len) {
                    $ord = ord($input[$i]);
                    if ($ord >= 0x30 && $ord <= 0x3F) {
                        $i++;
                    } else {
                        break;
                    }
                }

                // Skip intermediate bytes (0x20-0x2F)
                while ($i < $len) {
                    $ord = ord($input[$i]);
                    if ($ord >= 0x20 && $ord <= 0x2F) {
                        $i++;
                    } else {
                        break;
                    }
                }

                // Expect final byte (0x40-0x7E)
                if ($i < $len) {
                    $ord = ord($input[$i]);
                    if ($ord >= 0x40 && $ord <= 0x7E) {
                        $i++;
                        $tokens[] = new AnsiMatch(substr($input, $escStart, $i - $escStart));
                        $textStart = $i;

                        continue;
                    }
                }

                // Invalid CSI - treat as text
                $tokens[] = substr($input, $escStart, $i - $escStart);
                $textStart = $i;

            } elseif ($nextChar === ']') {
                // OSC sequence: ESC ] ... terminator
                $i++;

                // Find terminator: BEL (\x07), ST (\x9C), or ESC\
                while ($i < $len) {
                    $char = $input[$i];
                    if ($char === "\x07" || $char === "\x9C") {
                        $i++;
                        break;
                    } elseif ($char === "\x1B" && $i + 1 < $len && $input[$i + 1] === '\\') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }

                $tokens[] = new AnsiMatch(substr($input, $escStart, $i - $escStart));
                $textStart = $i;

            } elseif ($nextChar === '(' || $nextChar === ')' || $nextChar === '#') {
                // Character set or line attribute: ESC ( X, ESC ) X, ESC # X
                $i++;
                if ($i < $len) {
                    $i++;
                    $tokens[] = new AnsiMatch(substr($input, $escStart, $i - $escStart));
                    $textStart = $i;
                } else {
                    // Incomplete
                    $tokens[] = substr($input, $escStart, $i - $escStart);
                    $textStart = $i;
                }

            } elseif (isset(self::$simpleEscapes[$nextChar])) {
                // Simple escape command: ESC 7, ESC 8, etc.
                $i++;
                $tokens[] = new AnsiMatch(substr($input, $escStart, $i - $escStart));
                $textStart = $i;

            } else {
                // Unknown escape - treat ESC as text, continue from next char
                $tokens[] = "\x1B";
                $textStart = $i;
            }
        }

        return $tokens;
    }

    /**
     * Parse input using lightweight ParsedAnsi objects instead of AnsiMatch.
     *
     * This is faster because ParsedAnsi doesn't use regex for parsing.
     * Use this when you don't need full AnsiMatch compatibility.
     *
     * @param  string  $input  The input string to parse
     * @return array<string|ParsedAnsi> Array of text strings and parsed ANSI objects
     */
    public static function parseFast(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $tokens = [];
        $textStart = 0;
        $len = strlen($input);
        $i = 0;

        while ($i < $len) {
            // Fast path: scan for ESC character
            $escPos = strpos($input, "\x1B", $i);

            if ($escPos === false) {
                // No more escape sequences - rest is text
                if ($i < $len) {
                    $tokens[] = substr($input, $textStart);
                }
                break;
            }

            // Add text before this escape sequence
            if ($escPos > $textStart) {
                $tokens[] = substr($input, $textStart, $escPos - $textStart);
            }

            // Parse the escape sequence
            $escStart = $escPos;
            $i = $escPos + 1;

            if ($i >= $len) {
                // Incomplete escape at end
                $tokens[] = "\x1B";
                break;
            }

            $nextChar = $input[$i];

            if ($nextChar === '[') {
                // CSI sequence: ESC [ params final
                $i++;

                // Collect parameter bytes (0x30-0x3F: 0-9, ;, ?, <, =, >)
                while ($i < $len) {
                    $ord = ord($input[$i]);
                    if ($ord >= 0x30 && $ord <= 0x3F) {
                        $i++;
                    } else {
                        break;
                    }
                }

                // Skip intermediate bytes (0x20-0x2F)
                while ($i < $len) {
                    $ord = ord($input[$i]);
                    if ($ord >= 0x20 && $ord <= 0x2F) {
                        $i++;
                    } else {
                        break;
                    }
                }

                // Expect final byte (0x40-0x7E)
                if ($i < $len) {
                    $ord = ord($input[$i]);
                    if ($ord >= 0x40 && $ord <= 0x7E) {
                        $i++;
                        $tokens[] = new ParsedAnsi(substr($input, $escStart, $i - $escStart));
                        $textStart = $i;

                        continue;
                    }
                }

                // Invalid CSI - treat as text
                $tokens[] = substr($input, $escStart, $i - $escStart);
                $textStart = $i;

            } elseif ($nextChar === ']') {
                // OSC sequence: ESC ] ... terminator
                $i++;

                // Find terminator: BEL (\x07), ST (\x9C), or ESC\
                while ($i < $len) {
                    $char = $input[$i];
                    if ($char === "\x07" || $char === "\x9C") {
                        $i++;
                        break;
                    } elseif ($char === "\x1B" && $i + 1 < $len && $input[$i + 1] === '\\') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }

                $tokens[] = new ParsedAnsi(substr($input, $escStart, $i - $escStart));
                $textStart = $i;

            } elseif ($nextChar === '(' || $nextChar === ')' || $nextChar === '#') {
                // Character set or line attribute: ESC ( X, ESC ) X, ESC # X
                $i++;
                if ($i < $len) {
                    $i++;
                    $tokens[] = new ParsedAnsi(substr($input, $escStart, $i - $escStart));
                    $textStart = $i;
                } else {
                    // Incomplete
                    $tokens[] = substr($input, $escStart, $i - $escStart);
                    $textStart = $i;
                }

            } elseif (isset(self::$simpleEscapes[$nextChar])) {
                // Simple escape command: ESC 7, ESC 8, etc.
                $i++;
                $tokens[] = new ParsedAnsi(substr($input, $escStart, $i - $escStart));
                $textStart = $i;

            } else {
                // Unknown escape - treat ESC as text, continue from next char
                $tokens[] = "\x1B";
                $textStart = $i;
            }
        }

        return $tokens;
    }
}
