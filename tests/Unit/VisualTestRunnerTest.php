<?php

declare(strict_types=1);

namespace SoloTerm\Screen\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Screen\Tests\Support\VisualTestRunner;

class VisualTestRunnerTest extends TestCase
{
    #[Test]
    public function it_parses_requested_terminal_and_passthrough_phpunit_arguments(): void
    {
        $options = VisualTestRunner::parseArguments([
            'bin/test',
            '--screenshots',
            '--failed',
            '--terminal=ghosty',
            '--',
            '--filter',
            'vtail_test',
        ]);

        $this->assertTrue($options->screenshots);
        $this->assertFalse($options->missingOnly);
        $this->assertTrue($options->failedOnly);
        $this->assertSame('ghostty', $options->requestedTerminal);
        $this->assertSame(['--filter', 'vtail_test'], $options->phpunitArgs);
    }

    #[Test]
    public function it_knows_when_to_launch_a_fresh_requested_terminal_window(): void
    {
        $this->assertTrue(VisualTestRunner::shouldLaunchInFreshTerminal('iterm', true));
        $this->assertTrue(VisualTestRunner::shouldLaunchInFreshTerminal('ghostty', true));
        $this->assertFalse(VisualTestRunner::shouldLaunchInFreshTerminal(null, true));
        $this->assertFalse(VisualTestRunner::shouldLaunchInFreshTerminal('iterm', false));
    }

    #[Test]
    public function it_builds_a_relay_command_that_sets_a_capture_title_and_records_exit_status(): void
    {
        $command = VisualTestRunner::buildRelayCommand(
            terminal: 'ghostty',
            phpBinary: '/opt/homebrew/bin/php',
            scriptPath: '/Users/aaron/Code/soloterm/screen/bin/test',
            cwd: '/Users/aaron/Code/soloterm/screen',
            originalArgv: ['bin/test', '--missing', '--terminal=iterm', '--', '--filter', 'vtail_test'],
            captureTitle: 'soloterm-screen-abc123',
            resultPath: '/tmp/soloterm-screen.exit',
            logPath: '/tmp/soloterm-screen.log',
        );

        $this->assertStringContainsString("cd '/Users/aaron/Code/soloterm/screen'", $command);
        $this->assertStringContainsString("export SOLOTERM_SCREEN_FORCED_TERMINAL='ghostty'", $command);
        $this->assertStringContainsString("export SOLOTERM_SCREEN_CAPTURE_TITLE='soloterm-screen-abc123'", $command);
        $this->assertStringContainsString("export SOLOTERM_SCREEN_RELAY_LOG='/tmp/soloterm-screen.log'", $command);
        $this->assertStringContainsString("export SOLOTERM_SCREEN_RELAY_RESULT='/tmp/soloterm-screen.exit'", $command);
        $this->assertStringContainsString("printf '\\033]0;%s\\007'", $command);
        $this->assertStringContainsString('SOLOTERM_SCREEN_CAPTURE_TITLE', $command);
        $this->assertStringContainsString("'/opt/homebrew/bin/php' '/Users/aaron/Code/soloterm/screen/bin/test' '--missing' '--' '--filter' 'vtail_test'", $command);
        $this->assertStringNotContainsString('--terminal=iterm', $command);
        $this->assertStringContainsString('relay_exit_code=$?', $command);
        $this->assertStringContainsString('tee -a "$SOLOTERM_SCREEN_RELAY_LOG"', $command);
    }

    #[Test]
    public function it_builds_an_applescript_launcher_around_a_temp_shell_script(): void
    {
        $script = VisualTestRunner::buildItermLaunchAppleScript('/tmp/soloterm relay.sh');

        $this->assertStringContainsString('tell application "iTerm2"', $script);
        $this->assertStringContainsString('create window with default profile', $script);
        $this->assertStringContainsString('write text "/bin/zsh \'/tmp/soloterm relay.sh\'"', $script);
    }

    #[Test]
    public function it_builds_a_ghostty_launch_script_that_opens_a_new_window_and_resizes_it(): void
    {
        $script = VisualTestRunner::buildGhosttyLaunchAppleScript('/tmp/soloterm relay.sh', 180, 32);

        $this->assertStringContainsString('set ghosttyRunning to exists process "Ghostty"', $script);
        $this->assertStringContainsString('set priorWindowCount to count of windows', $script);
        $this->assertStringContainsString('keystroke "n" using command down', $script);
        $this->assertStringContainsString('if (count of windows) > priorWindowCount then', $script);
        $this->assertStringContainsString('error "Ghostty window did not appear."', $script);
        $this->assertStringContainsString('set position of window 1 to {100, 100}', $script);
        $this->assertStringContainsString('set size of window 1 to {1640, 690}', $script);
        $this->assertStringContainsString('keystroke "/bin/zsh \'/tmp/soloterm relay.sh\'"', $script);
    }

    #[Test]
    public function it_strips_terminal_arguments_before_relaying_into_iterm(): void
    {
        $args = VisualTestRunner::stripTerminalArguments([
            '--missing',
            '--terminal=iterm',
            '--filter',
            'ScrollTest',
            '--terminal',
            'ghostty',
            '--iterm',
        ]);

        $this->assertSame(['--missing', '--filter', 'ScrollTest'], $args);
    }

    #[Test]
    public function it_detects_when_pass_through_phpunit_args_already_include_a_filter(): void
    {
        $this->assertTrue(VisualTestRunner::phpunitArgsContainFilter(['--filter', 'ScrollTest']));
        $this->assertTrue(VisualTestRunner::phpunitArgsContainFilter(['--filter=ScrollTest']));
        $this->assertFalse(VisualTestRunner::phpunitArgsContainFilter(['tests/Unit/ScrollTest.php']));
    }
}
