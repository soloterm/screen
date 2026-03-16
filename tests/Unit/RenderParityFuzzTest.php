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

class RenderParityFuzzTest extends TestCase
{
    use FuzzesScreen;

    #[Test]
    public function full_render_stream_replays_to_identical_visible_state(): void
    {
        $this->assertFullRenderReplayParity([
            "Line 1\nLine 2",
            "\e[31mRed\e[0m",
            "\n\e(0lqqk\e(B",
        ], 40, 8);
    }

    #[Test]
    public function differential_render_stream_replays_incremental_updates_to_identical_visible_state(): void
    {
        $this->assertDifferentialReplayParity(
            initialOperations: [
                "AAA\nBBB\nCCC\nDDD\nEEE",
            ],
            updateOperations: [
                "\nFFF",
                "\e[2;1HCHG",
                "\e[31m!\e[0m",
                "\e[?1049hALT\e[?1049l",
            ],
            width: 20,
            height: 5,
        );
    }

    #[Test]
    public function resize_and_chunked_writes_match_one_shot_replay(): void
    {
        $this->assertChunkedOperationsParity([
            "header\nbody\n文🙂",
            ['resize', 8, 4],
            "\e[2;1Hupdated",
            ['resize', 6, 3],
            "\nfooter",
            ['resize', 10, 5],
            "\e[Htop",
        ], 12, 4);
    }
}
