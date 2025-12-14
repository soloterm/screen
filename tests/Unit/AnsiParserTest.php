<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Screen\AnsiMatch;
use SoloTerm\Screen\AnsiMatcher;
use SoloTerm\Screen\AnsiParser;
use SoloTerm\Screen\ParsedAnsi;

class AnsiParserTest extends TestCase
{
    #[Test]
    public function parses_plain_text(): void
    {
        $tokens = AnsiParser::parse('Hello World');

        $this->assertCount(1, $tokens);
        $this->assertEquals('Hello World', $tokens[0]);
    }

    #[Test]
    public function parses_empty_string(): void
    {
        $tokens = AnsiParser::parse('');

        $this->assertCount(0, $tokens);
    }

    #[Test]
    public function parses_csi_color_sequence(): void
    {
        $tokens = AnsiParser::parse("\e[31mRed\e[0m");

        // Filter out empty strings for comparison
        $tokens = array_values(array_filter($tokens, fn($t) => $t !== ''));

        $this->assertCount(3, $tokens);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[0]);
        $this->assertEquals('m', $tokens[0]->command);
        $this->assertEquals('31', $tokens[0]->params);
        $this->assertEquals('Red', $tokens[1]);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[2]);
        $this->assertEquals('m', $tokens[2]->command);
        $this->assertEquals('0', $tokens[2]->params);
    }

    #[Test]
    public function parses_cursor_movement(): void
    {
        $tokens = AnsiParser::parse("\e[10;20H");

        $this->assertCount(1, $tokens);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[0]);
        $this->assertEquals('H', $tokens[0]->command);
        $this->assertEquals('10;20', $tokens[0]->params);
    }

    #[Test]
    public function parses_cursor_up_down_forward_back(): void
    {
        $input = "\e[5A\e[3B\e[2C\e[1D";
        $tokens = AnsiParser::parse($input);

        $this->assertCount(4, $tokens);
        $this->assertEquals('A', $tokens[0]->command);
        $this->assertEquals('5', $tokens[0]->params);
        $this->assertEquals('B', $tokens[1]->command);
        $this->assertEquals('C', $tokens[2]->command);
        $this->assertEquals('D', $tokens[3]->command);
    }

    #[Test]
    public function parses_erase_sequences(): void
    {
        $tokens = AnsiParser::parse("\e[2J\e[K");

        $this->assertCount(2, $tokens);
        $this->assertEquals('J', $tokens[0]->command);
        $this->assertEquals('2', $tokens[0]->params);
        $this->assertEquals('K', $tokens[1]->command);
    }

    #[Test]
    public function parses_save_restore_cursor(): void
    {
        $tokens = AnsiParser::parse("\e7Hello\e8");

        $this->assertCount(3, $tokens);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[0]);
        $this->assertEquals('7', $tokens[0]->command);
        $this->assertEquals('Hello', $tokens[1]);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[2]);
        $this->assertEquals('8', $tokens[2]->command);
    }

    #[Test]
    public function parses_show_hide_cursor(): void
    {
        $tokens = AnsiParser::parse("\e[?25h\e[?25l");

        $this->assertCount(2, $tokens);
        $this->assertEquals('h', $tokens[0]->command);
        $this->assertEquals('?25', $tokens[0]->params);
        $this->assertEquals('l', $tokens[1]->command);
        $this->assertEquals('?25', $tokens[1]->params);
    }

    #[Test]
    public function parses_sgr_with_multiple_params(): void
    {
        $tokens = AnsiParser::parse("\e[1;31;44m");

        $this->assertCount(1, $tokens);
        $this->assertEquals('m', $tokens[0]->command);
        $this->assertEquals('1;31;44', $tokens[0]->params);
    }

    #[Test]
    public function parses_extended_256_color(): void
    {
        $tokens = AnsiParser::parse("\e[38;5;196m");

        $this->assertCount(1, $tokens);
        $this->assertEquals('m', $tokens[0]->command);
        $this->assertEquals('38;5;196', $tokens[0]->params);
    }

    #[Test]
    public function parses_extended_rgb_color(): void
    {
        $tokens = AnsiParser::parse("\e[38;2;255;128;0m");

        $this->assertCount(1, $tokens);
        $this->assertEquals('m', $tokens[0]->command);
        $this->assertEquals('38;2;255;128;0', $tokens[0]->params);
    }

    #[Test]
    public function parses_osc_sequence_with_bel(): void
    {
        $tokens = AnsiParser::parse("\e]0;Window Title\x07");

        $this->assertCount(1, $tokens);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[0]);
    }

    #[Test]
    public function parses_osc_sequence_with_st(): void
    {
        $tokens = AnsiParser::parse("\e]0;Window Title\e\\");

        $this->assertCount(1, $tokens);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[0]);
    }

    #[Test]
    public function parses_character_set_selection(): void
    {
        $tokens = AnsiParser::parse("\e(0\e(B");

        $this->assertCount(2, $tokens);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[0]);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[1]);
    }

    #[Test]
    public function parses_mixed_text_and_ansi(): void
    {
        $input = "Hello \e[31mRed\e[0m World";
        $tokens = AnsiParser::parse($input);

        $this->assertCount(5, $tokens);
        $this->assertEquals('Hello ', $tokens[0]);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[1]);
        $this->assertEquals('Red', $tokens[2]);
        $this->assertInstanceOf(AnsiMatch::class, $tokens[3]);
        $this->assertEquals(' World', $tokens[4]);
    }

    #[Test]
    public function parses_insert_lines(): void
    {
        $tokens = AnsiParser::parse("\e[5L");

        $this->assertCount(1, $tokens);
        $this->assertEquals('L', $tokens[0]->command);
        $this->assertEquals('5', $tokens[0]->params);
    }

    #[Test]
    public function parses_scroll_up_down(): void
    {
        $tokens = AnsiParser::parse("\e[3S\e[2T");

        $this->assertCount(2, $tokens);
        $this->assertEquals('S', $tokens[0]->command);
        $this->assertEquals('3', $tokens[0]->params);
        $this->assertEquals('T', $tokens[1]->command);
        $this->assertEquals('2', $tokens[1]->params);
    }

    #[Test]
    public function handles_incomplete_escape_as_text(): void
    {
        $tokens = AnsiParser::parse("Hello\e");

        // Incomplete escapes are treated as text (may be split from preceding text)
        $combined = implode('', array_filter($tokens, 'is_string'));
        $this->assertEquals("Hello\e", $combined);
    }

    #[Test]
    public function handles_incomplete_csi_as_text(): void
    {
        $tokens = AnsiParser::parse("Hello\e[31");

        // Incomplete CSI sequences are treated as text
        $combined = implode('', array_filter($tokens, 'is_string'));
        $this->assertEquals("Hello\e[31", $combined);
    }

    #[Test]
    public function matches_regex_parser_output_for_basic_cases(): void
    {
        $testCases = [
            'Hello World',
            "\e[31mRed\e[0m",
            "\e[10;20H",
            "\e[1;31;44mStyled\e[0m",
            "\e7\e8",
            "Text\e[Awith\e[Bmixed\e[Ccontent",
        ];

        foreach ($testCases as $input) {
            $regexTokens = AnsiMatcher::split($input);
            $stateTokens = AnsiParser::parse($input);

            $this->assertCount(
                count($regexTokens),
                $stateTokens,
                'Token count mismatch for: ' . json_encode($input)
            );

            for ($i = 0; $i < count($regexTokens); $i++) {
                $regex = $regexTokens[$i];
                $state = $stateTokens[$i];

                if ($regex instanceof AnsiMatch) {
                    $this->assertInstanceOf(AnsiMatch::class, $state);
                    $this->assertEquals(
                        $regex->command,
                        $state->command,
                        "Command mismatch at index $i for: " . json_encode($input)
                    );
                    $this->assertEquals(
                        $regex->params,
                        $state->params,
                        "Params mismatch at index $i for: " . json_encode($input)
                    );
                } else {
                    $this->assertIsString($state);
                    $this->assertEquals($regex, $state);
                }
            }
        }
    }

    #[Test]
    public function parse_fast_returns_parsed_ansi_objects(): void
    {
        $tokens = AnsiParser::parseFast("\e[31mRed\e[0m");

        $tokens = array_values(array_filter($tokens, fn($t) => $t !== ''));

        $this->assertCount(3, $tokens);
        $this->assertInstanceOf(ParsedAnsi::class, $tokens[0]);
        $this->assertEquals('m', $tokens[0]->command);
        $this->assertEquals('31', $tokens[0]->params);
        $this->assertEquals('Red', $tokens[1]);
        $this->assertInstanceOf(ParsedAnsi::class, $tokens[2]);
        $this->assertEquals('m', $tokens[2]->command);
        $this->assertEquals('0', $tokens[2]->params);
    }

    #[Test]
    public function parse_fast_handles_cursor_movement(): void
    {
        $tokens = AnsiParser::parseFast("\e[10;20H");

        $this->assertCount(1, $tokens);
        $this->assertInstanceOf(ParsedAnsi::class, $tokens[0]);
        $this->assertEquals('H', $tokens[0]->command);
        $this->assertEquals('10;20', $tokens[0]->params);
    }

    #[Test]
    public function parse_fast_handles_simple_escapes(): void
    {
        $tokens = AnsiParser::parseFast("\e7Hello\e8");

        $this->assertCount(3, $tokens);
        $this->assertInstanceOf(ParsedAnsi::class, $tokens[0]);
        $this->assertEquals('7', $tokens[0]->command);
        $this->assertEquals('Hello', $tokens[1]);
        $this->assertInstanceOf(ParsedAnsi::class, $tokens[2]);
        $this->assertEquals('8', $tokens[2]->command);
    }
}
