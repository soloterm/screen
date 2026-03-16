<?php

declare(strict_types=1);

namespace SoloTerm\Screen\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Screen\Tests\Support\TerminalEnvironment;
use SoloTerm\Screen\Tests\Support\VisualTestConfig;

class TerminalEnvironmentTest extends TestCase
{
    #[Test]
    public function make_identical_screen_uses_required_dimensions_when_visual_testing_is_disabled(): void
    {
        $config = new VisualTestConfig(
            terminal: 'ghostty',
            requiredLines: 32,
            requiredColumns: 180,
            fixturesRoot: 'tests/Fixtures',
            screenshotsRoot: 'tests/Screenshots',
            mode: VisualTestConfig::MODE_DISABLED,
        );

        $screen = (new TerminalEnvironment($config))->makeIdenticalScreen();

        $this->assertSame(180, $screen->width);
        $this->assertSame(32, $screen->height);
    }

    #[Test]
    public function with_output_preserves_existing_nested_output_buffers(): void
    {
        $config = new VisualTestConfig(
            terminal: 'ghostty',
            requiredLines: 32,
            requiredColumns: 180,
            fixturesRoot: 'tests/Fixtures',
            screenshotsRoot: 'tests/Screenshots',
            mode: VisualTestConfig::MODE_DISABLED,
        );

        $environment = new TerminalEnvironment($config);

        ob_start();
        echo 'outer';
        ob_start();
        echo 'inner';

        $levelsBefore = ob_get_level();
        $environment->withOutput(static function (): void {});
        $levelsAfter = ob_get_level();

        $inner = ob_get_clean();
        $outer = ob_get_clean();

        $this->assertSame($levelsBefore, $levelsAfter);
        $this->assertSame('inner', $inner);
        $this->assertSame('outer', $outer);
    }
}
