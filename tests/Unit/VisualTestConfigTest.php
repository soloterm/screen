<?php

declare(strict_types=1);

namespace SoloTerm\Screen\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Screen\Tests\Support\VisualTestConfig;

class VisualTestConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SOLOTERM_SCREEN_FORCED_TERMINAL');
        putenv('TERM_PROGRAM');
        putenv('ENABLE_SCREENSHOT_TESTING');
        VisualTestConfig::resetInstance();
    }

    #[Test]
    public function forced_terminal_environment_overrides_term_program_detection(): void
    {
        VisualTestConfig::resetInstance();
        putenv('SOLOTERM_SCREEN_FORCED_TERMINAL=iterm');
        putenv('TERM_PROGRAM=ghostty');
        putenv('ENABLE_SCREENSHOT_TESTING=1');

        $config = VisualTestConfig::fromEnvironment();

        $this->assertSame('iterm', $config->terminal);
        $this->assertTrue($config->screenshotTestingEnabled());
    }
}
