<?php

declare(strict_types=1);

namespace SoloTerm\Screen\Tests\Support;

final class VisualTestRunner
{
    public static function parseArguments(array $argv): VisualTestRunnerOptions
    {
        $args = array_slice($argv, 1);
        $phpunitArgs = [];
        $screenshots = false;
        $missingOnly = false;
        $failedOnly = false;
        $passthrough = false;
        $requestedTerminal = null;

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if ($arg === '--') {
                $passthrough = true;
                continue;
            }

            if (!$passthrough && ($arg === '--screenshots' || $arg === '-s')) {
                $screenshots = true;
                continue;
            }

            if (!$passthrough && ($arg === '--missing' || $arg === '-m')) {
                $missingOnly = true;
                continue;
            }

            if (!$passthrough && $arg === '--failed') {
                $failedOnly = true;
                continue;
            }

            if (!$passthrough && ($arg === '--terminal' || $arg === '-t')) {
                $requestedTerminal = self::normalizeTerminal($args[$i + 1] ?? null);
                $i++;
                continue;
            }

            if (!$passthrough && str_starts_with($arg, '--terminal=')) {
                $requestedTerminal = self::normalizeTerminal(substr($arg, 11));
                continue;
            }

            if (!$passthrough && $arg === '--iterm') {
                $requestedTerminal = 'iterm';
                continue;
            }

            $phpunitArgs[] = $arg;
        }

        return new VisualTestRunnerOptions(
            screenshots: $screenshots,
            missingOnly: $missingOnly,
            failedOnly: $failedOnly,
            phpunitArgs: $phpunitArgs,
            requestedTerminal: $requestedTerminal,
        );
    }

    public static function normalizeTerminal(?string $terminal): ?string
    {
        if ($terminal === null || $terminal === '') {
            return null;
        }

        return match (strtolower($terminal)) {
            'iterm', 'iterm2' => 'iterm',
            'ghostty', 'ghosty' => 'ghostty',
            default => null,
        };
    }

    public static function shouldLaunchInFreshTerminal(
        ?string $requestedTerminal,
        bool $screenshotModeRequested
    ): bool {
        return $screenshotModeRequested && $requestedTerminal !== null;
    }

    public static function buildRelayCommand(
        string $terminal,
        string $phpBinary,
        string $scriptPath,
        string $cwd,
        array $originalArgv,
        string $captureTitle,
        string $resultPath,
        string $logPath,
    ): string {
        $scriptInvocation = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath);
        $scriptArgs = self::stripTerminalArguments(array_slice($originalArgv, 1));

        if ($scriptArgs !== []) {
            $scriptInvocation .= ' ' . implode(' ', array_map('escapeshellarg', $scriptArgs));
        }

        return implode("\n", [
            'cd ' . escapeshellarg($cwd),
            'export SOLOTERM_SCREEN_FORCED_TERMINAL=' . escapeshellarg($terminal),
            'export SOLOTERM_SCREEN_CAPTURE_TITLE=' . escapeshellarg($captureTitle),
            'export SOLOTERM_SCREEN_RELAY_LOG=' . escapeshellarg($logPath),
            'export SOLOTERM_SCREEN_RELAY_RESULT=' . escapeshellarg($resultPath),
            'print -r -- "[relay start] $(date)" >| "$SOLOTERM_SCREEN_RELAY_LOG"',
            'print -r -- "[relay cwd] $PWD" >> "$SOLOTERM_SCREEN_RELAY_LOG"',
            'print -r -- "[relay command] ' . self::escapeLogMessage($scriptInvocation) . '" >> "$SOLOTERM_SCREEN_RELAY_LOG"',
            'printf \'\\033]0;%s\\007\' "$SOLOTERM_SCREEN_CAPTURE_TITLE"',
            'set -o pipefail',
            $scriptInvocation . ' 2>&1 | tee -a "$SOLOTERM_SCREEN_RELAY_LOG"',
            'relay_exit_code=$?',
            'print -r -- "[relay exit] ${relay_exit_code}" >> "$SOLOTERM_SCREEN_RELAY_LOG"',
            'printf \'%s\' "$relay_exit_code" >| "$SOLOTERM_SCREEN_RELAY_RESULT"',
            'exit "$relay_exit_code"',
        ]);
    }

    public static function buildItermLaunchCommand(string $relayScriptPath): string
    {
        return '/bin/zsh ' . escapeshellarg($relayScriptPath);
    }

    public static function buildItermLaunchAppleScript(string $relayScriptPath): string
    {
        $launchCommand = self::buildItermLaunchCommand($relayScriptPath);

        return sprintf(
            'tell application "iTerm2"
                activate
                set relayWindow to (create window with default profile)
                tell current session of relayWindow
                    write text "%s"
                end tell
            end tell',
            self::escapeAppleScriptString($launchCommand)
        );
    }

    public static function buildGhosttyLaunchAppleScript(
        string $relayScriptPath,
        int $columns,
        int $lines
    ): string {
        $windowWidth = ($columns * 9) + 20;
        $windowHeight = ($lines * 20) + 50;
        $launchCommand = self::buildItermLaunchCommand($relayScriptPath);

        return sprintf(
            'tell application "System Events"
                set ghosttyRunning to exists process "Ghostty"
                set priorWindowCount to 0
                if ghosttyRunning then
                    tell process "Ghostty"
                        set priorWindowCount to count of windows
                    end tell
                end if
            end tell
            tell application "Ghostty"
                activate
            end tell
            repeat 40 times
                tell application "System Events"
                    if exists process "Ghostty" then
                        exit repeat
                    end if
                end tell
                delay 0.1
            end repeat
            tell application "System Events"
                tell process "Ghostty"
                    set frontmost to true
                end tell
            end tell
            if ghosttyRunning then
                tell application "System Events"
                    keystroke "n" using command down
                end tell
            end if
            repeat 60 times
                tell application "System Events"
                    tell process "Ghostty"
                        if (count of windows) > priorWindowCount then
                            exit repeat
                        end if
                    end tell
                end tell
                delay 0.1
            end repeat
            tell application "System Events"
                tell process "Ghostty"
                    if (count of windows) <= priorWindowCount then
                        error "Ghostty window did not appear."
                    end if
                    set frontmost to true
                    set position of window 1 to {100, 100}
                    set size of window 1 to {%d, %d}
                end tell
            end tell
            delay 0.2
            tell application "System Events"
                keystroke "%s"
                key code 36
            end tell',
            $windowWidth,
            $windowHeight,
            self::escapeAppleScriptString($launchCommand)
        );
    }

    public static function escapeAppleScriptString(string $value): string
    {
        return str_replace(
            ["\\", '"'],
            ["\\\\", '\\"'],
            $value,
        );
    }

    public static function escapeLogMessage(string $value): string
    {
        return str_replace(
            ['\\', '"', '$', '`'],
            ['\\\\', '\\"', '\\$', '\\`'],
            $value,
        );
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    public static function stripTerminalArguments(array $args): array
    {
        $stripped = [];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if ($arg === '--terminal' || $arg === '-t') {
                $i++;
                continue;
            }

            if (str_starts_with($arg, '--terminal=')) {
                continue;
            }

            if ($arg === '--iterm') {
                continue;
            }

            $stripped[] = $arg;
        }

        return $stripped;
    }

    /**
     * @param  list<string>  $args
     */
    public static function phpunitArgsContainFilter(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg === '--filter' || str_starts_with($arg, '--filter=')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    public static function appendPhpunitOption(array $args, string $option, string $value): array
    {
        $args[] = $option;
        $args[] = $value;

        return $args;
    }
}
