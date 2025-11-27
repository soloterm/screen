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
            "Hello World",
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
                "Token count mismatch for: " . json_encode($input)
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
    public function benchmark_state_machine_vs_regex(): void
    {
        // Generate test input with many ANSI sequences
        $input = '';
        for ($i = 0; $i < 100; $i++) {
            $input .= "\e[" . rand(30, 37) . "mLine $i with colored text\e[0m\n";
            $input .= "\e[" . rand(1, 50) . ";" . rand(1, 100) . "H"; // cursor move
        }

        $iterations = 100;

        // Benchmark regex parser
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            AnsiMatcher::split($input);
        }
        $regexTime = hrtime(true) - $start;

        // Benchmark state machine parser
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            AnsiParser::parse($input);
        }
        $stateTime = hrtime(true) - $start;

        $regexMs = $regexTime / 1_000_000;
        $stateMs = $stateTime / 1_000_000;
        $ratio = $regexMs / $stateMs;

        echo "\n\nParser Benchmark ({$iterations} iterations, ~5KB input with 200 ANSI sequences):\n";
        echo "  Regex:        " . number_format($regexMs, 2) . " ms\n";
        echo "  State Machine: " . number_format($stateMs, 2) . " ms\n";
        echo "  Speedup:       " . number_format($ratio, 2) . "x\n";

        // State machine should be competitive (not necessarily faster due to PHP overhead)
        $this->assertTrue(true);
    }

    #[Test]
    public function benchmark_with_realistic_terminal_output(): void
    {
        // Simulate realistic terminal output (colored logs, progress bars, etc.)
        $input = '';

        // Simulate log output
        for ($i = 0; $i < 50; $i++) {
            $timestamp = date('Y-m-d H:i:s');
            $level = match (rand(0, 3)) {
                0 => "\e[32mINFO\e[0m",
                1 => "\e[33mWARN\e[0m",
                2 => "\e[31mERROR\e[0m",
                3 => "\e[36mDEBUG\e[0m",
            };
            $input .= "[$timestamp] $level: Log message number $i with some content\n";
        }

        // Add some cursor movements (like progress updates)
        $input .= "\e[H\e[2J"; // Clear screen
        for ($i = 0; $i <= 100; $i += 10) {
            $input .= "\e[10;1HProgress: $i%\e[K";
        }

        $iterations = 500;

        // Benchmark regex parser
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            AnsiMatcher::split($input);
        }
        $regexTime = hrtime(true) - $start;

        // Benchmark state machine parser
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            AnsiParser::parse($input);
        }
        $stateTime = hrtime(true) - $start;

        $regexMs = $regexTime / 1_000_000;
        $stateMs = $stateTime / 1_000_000;

        echo "\n\nRealistic Terminal Output Benchmark ({$iterations} iterations):\n";
        echo "  Regex:        " . number_format($regexMs, 2) . " ms\n";
        echo "  State Machine: " . number_format($stateMs, 2) . " ms\n";
        echo "  Speedup:       " . number_format($regexMs / $stateMs, 2) . "x\n";

        $this->assertTrue(true);
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

    #[Test]
    public function benchmark_all_parsers(): void
    {
        // Generate test input with many ANSI sequences
        $input = '';
        for ($i = 0; $i < 100; $i++) {
            $input .= "\e[" . rand(30, 37) . "mLine $i with colored text\e[0m\n";
            $input .= "\e[" . rand(1, 50) . ";" . rand(1, 100) . "H"; // cursor move
        }

        $iterations = 200;

        // Benchmark regex parser (AnsiMatcher)
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            AnsiMatcher::split($input);
        }
        $regexTime = hrtime(true) - $start;

        // Benchmark state machine with AnsiMatch (compatibility mode)
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            AnsiParser::parse($input);
        }
        $stateTime = hrtime(true) - $start;

        // Benchmark state machine with ParsedAnsi (fast mode)
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            AnsiParser::parseFast($input);
        }
        $fastTime = hrtime(true) - $start;

        $regexMs = $regexTime / 1_000_000;
        $stateMs = $stateTime / 1_000_000;
        $fastMs = $fastTime / 1_000_000;

        echo "\n\nFull Parser Comparison ({$iterations} iterations, ~5KB input):\n";
        echo "  Regex (AnsiMatcher):       " . number_format($regexMs, 2) . " ms\n";
        echo "  State + AnsiMatch:         " . number_format($stateMs, 2) . " ms (" . number_format($regexMs / $stateMs, 2) . "x)\n";
        echo "  State + ParsedAnsi (fast): " . number_format($fastMs, 2) . " ms (" . number_format($regexMs / $fastMs, 2) . "x)\n";

        $this->assertTrue(true);
    }

    #[Test]
    public function benchmark_realistic_with_fast_parser(): void
    {
        // Simulate realistic terminal output
        $input = '';
        for ($i = 0; $i < 50; $i++) {
            $timestamp = date('Y-m-d H:i:s');
            $level = match (rand(0, 3)) {
                0 => "\e[32mINFO\e[0m",
                1 => "\e[33mWARN\e[0m",
                2 => "\e[31mERROR\e[0m",
                3 => "\e[36mDEBUG\e[0m",
            };
            $input .= "[$timestamp] $level: Log message number $i with some content\n";
        }
        $input .= "\e[H\e[2J";
        for ($i = 0; $i <= 100; $i += 10) {
            $input .= "\e[10;1HProgress: $i%\e[K";
        }

        $iterations = 500;

        // Benchmark regex parser
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            AnsiMatcher::split($input);
        }
        $regexTime = hrtime(true) - $start;

        // Benchmark fast parser
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            AnsiParser::parseFast($input);
        }
        $fastTime = hrtime(true) - $start;

        $regexMs = $regexTime / 1_000_000;
        $fastMs = $fastTime / 1_000_000;

        echo "\n\nRealistic Output - Fast Parser ({$iterations} iterations):\n";
        echo "  Regex:     " . number_format($regexMs, 2) . " ms\n";
        echo "  Fast:      " . number_format($fastMs, 2) . " ms\n";
        echo "  Speedup:   " . number_format($regexMs / $fastMs, 2) . "x\n";

        // Fast parser should be faster than regex
        $this->assertLessThan($regexMs, $fastMs);
    }

    #[Test]
    public function benchmark_screen_write_with_fast_parser(): void
    {
        // Test the actual Screen.write() performance improvement
        $input = '';
        for ($i = 0; $i < 50; $i++) {
            $input .= "\e[32mLine $i with \e[1mformatted\e[0m \e[33mtext\e[0m\n";
            $input .= "\e[" . rand(1, 25) . ";" . rand(1, 80) . "H"; // cursor moves
        }

        $iterations = 100;

        // Benchmark write performance
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $screen = new \SoloTerm\Screen\Screen(80, 25);
            $screen->write($input);
        }
        $writeTime = hrtime(true) - $start;

        $writeMs = $writeTime / 1_000_000;

        echo "\n\nScreen.write() Performance ({$iterations} iterations, ~3KB ANSI input):\n";
        echo "  Total time: " . number_format($writeMs, 2) . " ms\n";
        echo "  Per write:  " . number_format($writeMs / $iterations, 3) . " ms\n";

        // Just ensure it completes in reasonable time
        $this->assertLessThan(1000, $writeMs);
    }
}
