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
use SoloTerm\Screen\Tests\Support\FuzzesScreen;

class ReplayCorpusTest extends TestCase
{
    use FuzzesScreen;

    #[Test]
    public function laravel_prompts_transcript_matches_full_replay_under_random_byte_chunking(): void
    {
        $this->assertChunkedOperationsParity([implode(PHP_EOL, [
            "\e[?25l",
            "\e[90m ┌\e[39m \e[36mWhat should the model be named?\e[39m \e[90m─────────────────────────────┐\e[39m",
            "\e[90m │\e[39m \e[2m\e[7mE\e[27m.g. Flight\e[22m                                                  \e[90m│\e[39m",
            "\e[90m └──────────────────────────────────────────────────────────────┘\e[39m",
            '',
            '',
        ])], 120, 12);
    }

    #[Test]
    public function progress_style_transcript_matches_full_replay_under_random_byte_chunking(): void
    {
        $this->assertChunkedOperationsParity([
            "\e[32mBuilding\e[39m\r[1/3] downloading...\r[2/3] extracting...\r[3/3] done\n",
        ], 80, 8);
    }

    #[Test]
    public function alternate_screen_and_charset_transcript_matches_full_replay_under_random_byte_chunking(): void
    {
        $this->assertChunkedOperationsParity([
            'main menu',
            "\e[?1049h",
            "\e(0lqqk\e(B",
            "\nalt body",
            "\e[?1049l",
            "\nrestored",
        ], 80, 12);
    }

    #[Test]
    public function multibyte_cursor_transcript_matches_full_replay_under_random_byte_chunking(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Multibyte chunked replay parity for this edge case is currently validated on macOS only.');
        }

        $this->assertChunkedOperationsParity([
            '🙂🙂🙂',
            "\e[1G",
            '文',
            "\e[2B",
            '❤️ done',
        ], 80, 8);
    }

    #[Test]
    public function scrolling_log_transcript_matches_full_replay_under_random_byte_chunking(): void
    {
        $this->assertChunkedOperationsParity([
            implode(PHP_EOL, array_map(
                static fn(int $line) => sprintf("\e[3%dmline %02d\e[39m", ($line % 7) + 1, $line),
                range(1, 18)
            )),
        ], 80, 6);
    }
}
