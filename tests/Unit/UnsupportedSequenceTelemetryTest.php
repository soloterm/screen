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
use SoloTerm\Screen\Screen;

class UnsupportedSequenceTelemetryTest extends TestCase
{
    #[Test]
    public function parsed_but_unhandled_sequences_are_reported_via_callback(): void
    {
        $screen = new Screen(80, 24);

        $this->assertTrue(
            is_callable([$screen, 'reportUnhandledSequencesVia']),
            'Screen needs a reportUnhandledSequencesVia(Closure $closure) hook.'
        );

        if (!is_callable([$screen, 'reportUnhandledSequencesVia'])) {
            return;
        }

        $unhandled = [];
        $screen->reportUnhandledSequencesVia(function (string $raw) use (&$unhandled): void {
            $unhandled[] = $raw;
        });

        $screen->write("\e]0;Window title\x07\e[?2004h\e[5n");

        $this->assertSame([
            "\e]0;Window title\x07",
            "\e[?2004h",
            "\e[5n",
        ], $unhandled);
    }

    #[Test]
    public function supported_sequences_are_not_reported_as_unhandled(): void
    {
        $screen = new Screen(80, 24);

        $this->assertTrue(
            is_callable([$screen, 'reportUnhandledSequencesVia']),
            'Screen needs a reportUnhandledSequencesVia(Closure $closure) hook.'
        );

        if (!is_callable([$screen, 'reportUnhandledSequencesVia'])) {
            return;
        }

        $unhandled = [];
        $responses = [];

        $screen->reportUnhandledSequencesVia(function (string $raw) use (&$unhandled): void {
            $unhandled[] = $raw;
        });

        $screen->respondToQueriesVia(function (string $response) use (&$responses): void {
            $responses[] = $response;
        });

        $screen->write("\e[31mRed\e[0m\e[6n\e]10;?\x07\e[?1049halt\e[?1049l");

        $this->assertSame([], $unhandled);
        $this->assertSame([
            "\e[1;4R",
            "\e]10;rgb:0000/0000/0000\e\\",
        ], $responses);
    }
}
