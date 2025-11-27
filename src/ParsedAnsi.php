<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen;

/**
 * Lightweight ANSI escape sequence representation.
 *
 * Unlike AnsiMatch which uses regex to parse, this class extracts
 * command and params through direct string manipulation for better performance.
 */
class ParsedAnsi implements \Stringable
{
    public ?string $command;

    public ?string $params;

    public function __construct(public string $raw)
    {
        $len = strlen($raw);

        // Minimum valid sequence is ESC + something (2 chars)
        if ($len < 2 || $raw[0] !== "\x1B") {
            $this->command = null;
            $this->params = null;

            return;
        }

        $second = $raw[1];

        if ($second === '[') {
            // CSI sequence: ESC [ params command
            // Command is the last character, params is everything between
            if ($len > 2) {
                $this->command = $raw[$len - 1];
                $this->params = $len > 3 ? substr($raw, 2, $len - 3) : '';
            } else {
                $this->command = null;
                $this->params = null;
            }
        } elseif ($second === ']') {
            // OSC sequence: ESC ] num ; string terminator
            // Extract the number before semicolon as "command"
            $semicolonPos = strpos($raw, ';', 2);
            if ($semicolonPos !== false) {
                $this->command = substr($raw, 2, $semicolonPos - 2);
                $this->params = '?'; // OSC uses ? params format
            } else {
                $this->command = null;
                $this->params = null;
            }
        } else {
            // Simple escape: ESC command
            $this->command = $second;
            $this->params = '';
        }
    }

    public function __toString(): string
    {
        return $this->raw;
    }
}
