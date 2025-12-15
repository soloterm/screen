<?php

declare(strict_types=1);

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Support;

use Exception;
use ReflectionClass;
use SoloTerm\Screen\Screen;
use Symfony\Component\Console\Terminal;

class TerminalEnvironment
{
    private static ?bool $userAcceptedResize = null;

    public function __construct(
        private readonly VisualTestConfig $config,
    ) {}

    public function makeIdenticalScreen(): Screen
    {
        $terminal = new Terminal;

        (new ReflectionClass($terminal))->getMethod('initDimensions')->invoke($terminal);

        return new Screen($terminal->getWidth(), $terminal->getHeight());
    }

    public function ensureRequiredSize(): void
    {
        $screen = $this->makeIdenticalScreen();

        if ($screen->height === $this->config->requiredLines && $screen->width === $this->config->requiredColumns) {
            return;
        }

        if ($this->offerToResize()) {
            $screen = $this->makeIdenticalScreen();
        }

        if ($screen->height !== $this->config->requiredLines || $screen->width !== $this->config->requiredColumns) {
            throw new Exception(sprintf(
                "Fixtures must be generated with LINES=%d COLUMNS=%d to match CI.\n" .
                "Current dimensions: LINES=%d COLUMNS=%d\n" .
                'Run: LINES=%d COLUMNS=%d ENABLE_SCREENSHOT_TESTING=2 composer test',
                $this->config->requiredLines,
                $this->config->requiredColumns,
                $screen->height,
                $screen->width,
                $this->config->requiredLines,
                $this->config->requiredColumns
            ));
        }
    }

    public function offerToResize(): bool
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        if (!$this->config->hasValidTerminal()) {
            return false;
        }

        if (self::$userAcceptedResize === false) {
            return false;
        }

        if (self::$userAcceptedResize === null) {
            echo "\n";
            echo "Terminal dimensions don't match CI (need {$this->config->requiredColumns}x{$this->config->requiredLines}).\n";
            echo "Would you like to resize {$this->config->terminal} automatically? [Y/n] ";

            $handle = fopen('php://stdin', 'r');
            $input = trim(fgets($handle));
            fclose($handle);

            self::$userAcceptedResize = $input === '' || strtolower($input) === 'y';

            if (!self::$userAcceptedResize) {
                return false;
            }
        }

        return $this->resizeTerminal();
    }

    private function resizeTerminal(): bool
    {
        return match ($this->config->terminal) {
            'iterm' => $this->resizeIterm(),
            'ghostty' => $this->resizeGhostty(),
            default => false,
        };
    }

    private function resizeIterm(): bool
    {
        $script = sprintf(
            'tell application "iTerm2"
                tell current session of current window
                    set columns to %d
                    set rows to %d
                end tell
            end tell',
            $this->config->requiredColumns,
            $this->config->requiredLines
        );

        exec('osascript -e ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        if ($exitCode === 0) {
            usleep(100000); // 100ms
            echo "iTerm resized to {$this->config->requiredColumns}x{$this->config->requiredLines}.\n";

            return true;
        }

        echo "Failed to resize iTerm.\n";

        return false;
    }

    private function resizeGhostty(): bool
    {
        $cellWidth = 9;
        $cellHeight = 20;

        $windowWidth = ($this->config->requiredColumns * $cellWidth) + 20;
        $windowHeight = ($this->config->requiredLines * $cellHeight) + 50;

        $script = sprintf(
            'tell application "Ghostty"
                set bounds of window 1 to {100, 100, %d, %d}
            end tell',
            100 + $windowWidth,
            100 + $windowHeight
        );

        exec('osascript -e ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        if ($exitCode === 0) {
            usleep(200000); // 200ms
            echo "Ghostty window resized (target: {$this->config->requiredColumns}x{$this->config->requiredLines}).\n";
            echo "Note: Ghostty resize is approximate. Please verify dimensions.\n";

            return true;
        }

        $script2 = sprintf(
            'tell application "System Events"
                tell process "Ghostty"
                    set size of window 1 to {%d, %d}
                end tell
            end tell',
            $windowWidth,
            $windowHeight
        );

        exec('osascript -e ' . escapeshellarg($script2) . ' 2>&1', $output2, $exitCode2);

        if ($exitCode2 === 0) {
            usleep(200000);
            echo "Ghostty window resized via System Events (target: {$this->config->requiredColumns}x{$this->config->requiredLines}).\n";

            return true;
        }

        echo "Failed to resize Ghostty. Please resize manually to {$this->config->requiredColumns}x{$this->config->requiredLines}.\n";

        return false;
    }

    public function restoreTerminal(): void
    {
        echo "\ec"; // Brute force reset of terminal.
    }

    public function clearAndPrepare(): void
    {
        $this->restoreTerminal();

        echo "\e[0m"; // Reset styles
        echo "\e[H"; // Move cursor home
        echo "\e[2J"; // Clear screen
        echo "\e[?25l"; // Hide cursor
    }

    public function withOutput(callable $callback): mixed
    {
        $obLevel = ob_get_level();

        if ($obLevel === 0) {
            return $callback();
        }

        $captured = ob_get_clean();

        try {
            return $callback();
        } finally {
            ob_start();
            echo $captured;
        }
    }

    public function waitForKeypress(): void
    {
        system('stty cbreak -echo');
        fgetc(STDIN);
        system('stty sane');
    }

    public static function resetStaticState(): void
    {
        self::$userAcceptedResize = null;
    }
}
