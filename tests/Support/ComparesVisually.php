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
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use SoloTerm\Screen\Screen;
use Symfony\Component\Console\Terminal;

trait ComparesVisually
{
    protected $testsPerMethod = [
        //
    ];

    protected ?array $uniqueTestIdentifier = null;

    /**
     * Get the current terminal emulator name.
     * Returns 'iterm', 'ghostty', or null if unknown/unsupported.
     *
     * Screenshot tests must be run in the actual terminal - no override allowed.
     * This ensures fixtures are generated and validated in the correct environment.
     */
    protected function detectTerminal(): ?string
    {
        $termProgram = getenv('TERM_PROGRAM');

        if ($termProgram === 'iTerm.app') {
            return 'iterm';
        }

        if ($termProgram === 'ghostty') {
            return 'ghostty';
        }

        return null;
    }

    /**
     * Asserts that the given $content visually matches what would appear in the terminal.
     * This method takes screenshots of both the raw content rendered in the terminal and
     * an emulated version, then compares them pixel-by-pixel.
     *
     * @throws Exception
     */
    public function assertTerminalMatch(array|string $content, $iterate = false): void
    {
        // Just a little convenience for passing in a bunch of content.
        if (is_array($content) && !$iterate) {
            $content = implode(PHP_EOL, $content);
        }

        if (is_string($content)) {
            $content = [$content];
        }

        $this->uniqueTestIdentifier = $this->uniqueTestIdentifier();

        $shouldRunVisualTest = getenv('ENABLE_SCREENSHOT_TESTING') === '1'
            || getenv('ENABLE_SCREENSHOT_TESTING') === '2' && $this->getFixture($content) === false;

        if ($shouldRunVisualTest) {
            $this->withOutputEnabled(fn() => $this->assertVisualMatch($content));
        } else {
            $this->assertFixtureMatch($content);
        }
    }

    protected function getFixture(array $content)
    {
        if (!file_exists($this->fixturePath())) {
            return false;
        }

        $fixture = file_get_contents($this->fixturePath());
        $fixture = json_decode($fixture, true);

        if ($fixture['checksum'] !== md5(json_encode($content))) {
            return false;
        }

        return $fixture;
    }

    protected function assertFixtureMatch(array $content): bool
    {
        $fixture = $this->getFixture($content);

        if (!$fixture) {
            $this->markTestSkipped('Fixture with correct content does not exist for ' . $this->uniqueTestIdentifier[1] . '. Looked in ' . $this->fixturePath());
        }

        $screen = new Screen($fixture['width'], $fixture['height']);

        foreach ($content as $c) {
            $screen->write($c);
        }

        $this->assertEquals($fixture['output'], $screen->output());

        return true;
    }

    protected function assertVisualMatch(array $content, $attempt = 1)
    {
        $terminal = $this->detectTerminal();

        if (!$terminal) {
            $this->markTestSkipped('Visual testing requires iTerm or Ghostty. Current terminal: ' . (getenv('TERM_PROGRAM') ?: 'unknown'));
        }

        $terminalPath = $this->screenshotPath($terminal);
        $emulatedPath = $this->screenshotPath('emulated');

        $this->captureCleanOutput($terminalPath, $content);

        $screen = $this->makeIdenticalScreen();

        foreach ($content as $c) {
            $screen->write($c);
        }

        $emulated = $screen->output();

        $this->captureCleanOutput($emulatedPath, [$emulated]);

        $matched = $this->terminalAreaIsIdentical($terminalPath, $emulatedPath);

        // Due to the nature of screenshotting etc, these can be flaky.
        if (!$matched && $attempt === 1) {
            $this->assertVisualMatch($content, ++$attempt);

            return;
        }

        if ($matched) {
            $this->writeFixtureFile($content);
        }

        $this->assertTrue(
            $matched,
            'Failed asserting that screenshots are identical. Diff available at ' . $this->screenshotPath('diff')
        );
    }

    protected function writeFixtureFile($content)
    {
        // Fixtures must be generated at the same dimensions as CI to ensure checksums match.
        // CI uses LINES=32 COLUMNS=180 (see .github/workflows/tests.yml)
        $requiredLines = 32;
        $requiredColumns = 180;

        $screen = $this->makeIdenticalScreen();

        if ($screen->height !== $requiredLines || $screen->width !== $requiredColumns) {
            // Check if we can resize the current terminal
            if ($this->offerToResizeTerminal($requiredLines, $requiredColumns)) {
                // User accepted and resize was attempted, recreate screen with new dimensions
                $screen = $this->makeIdenticalScreen();
            }

            // Check again after potential resize
            if ($screen->height !== $requiredLines || $screen->width !== $requiredColumns) {
                $terminal = $this->detectTerminal() ?? 'your terminal';
                $this->fail(sprintf(
                    "Fixtures must be generated with LINES=%d COLUMNS=%d to match CI.\n" .
                    "Current dimensions: LINES=%d COLUMNS=%d\n" .
                    'Run: LINES=%d COLUMNS=%d ENABLE_SCREENSHOT_TESTING=2 composer test',
                    $requiredLines,
                    $requiredColumns,
                    $screen->height,
                    $screen->width,
                    $requiredLines,
                    $requiredColumns
                ));
            }
        }

        $this->ensureDirectoriesExist($this->fixturePath());

        foreach ($content as $c) {
            $screen->write($c);
        }

        file_put_contents($this->fixturePath(), json_encode([
            'checksum' => md5(json_encode($content)),
            'width' => $screen->width,
            'height' => $screen->height,
            'output' => $screen->output()
        ]));
    }

    /**
     * Find the debug backtrace frame that called `assertTerminalMatch()`.
     *
     * @throws Exception If the caller cannot be found.
     */
    protected function uniqueTestIdentifier(): array
    {
        $assertFound = false;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_ends_with($frame['file'], 'ComparesVisually.php')) {
                continue;
            }

            if (!isset($frame['class'])) {
                continue;
            }

            $reflection = new ReflectionClass($frame['class']);
            $method = $reflection->getMethod($frame['function']);
            $isTest = $method->getAttributes(Test::class);

            if (count($isTest)) {
                $parts = explode('\\Tests\\', $frame['class'], 2);
                $path = str_replace('\\', '/', $parts[1]);
                $function = $frame['function'];

                $key = "$path::$function";

                if (!array_key_exists($key, $this->testsPerMethod)) {
                    $this->testsPerMethod[$key] = 0;
                }

                $function = $function . '_' . ++$this->testsPerMethod[$key];

                return [$path, $function];
            }
        }

        throw new Exception('Unable to find caller in debug backtrace.');
    }

    /**
     * Execute a callback with output buffering disabled, then restore it.
     *
     * @return mixed
     */
    protected function withOutputEnabled(callable $cb)
    {
        $obLevel = ob_get_level();

        // If no output buffering, just run the callback.
        if ($obLevel === 0) {
            return $cb();
        }

        // Flush current buffer and temporarily disable output buffering.
        $captured = ob_get_clean();

        try {
            return $cb();
        } finally {
            // Re-enable output buffering and restore captured output.
            ob_start();
            echo $captured;
        }
    }

    protected function ensureDirectoriesExist($path)
    {
        // Ensure directories exist
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new Exception("Could not create directory $dir");
        }
    }

    /**
     * Capture the provided $content from the terminal by:
     * - Clearing the screen, writing $content.
     * - Taking a screenshot of the terminal window.
     * - Restoring the terminal state.
     *
     * @param  string  $filename  The filename to save the screenshot to.
     * @param  array  $content  The content to be rendered.
     *
     * @throws Exception If screencapture fails or terminal window not found.
     */
    protected function captureCleanOutput(string $filename, array $content): void
    {
        $this->ensureDirectoriesExist($filename);

        $this->restoreTerminal();

        echo "\e[0m"; // Reset styles
        echo "\e[H"; // Move cursor home
        echo "\e[2J"; // Clear screen
        echo "\e[?25l"; // Hide cursor

        foreach ($content as $c) {
            echo $c;
            // Give time for the screen to update visually
            usleep(10_000);
        }

        // Bring our terminal window to front before capturing
        // This ensures we capture the correct window when multiple are open
        $this->activateTerminalWindow();
        usleep(100_000); // 100ms for window to come to front

        $windowId = $this->getTerminalWindowId();

        if (empty($windowId)) {
            $this->restoreTerminal();
            $terminal = $this->detectTerminal() ?? 'unknown';
            $debug = $this->lastWindowIdDebugInfo ? "\n\nDebug info:\n{$this->lastWindowIdDebugInfo}" : '';
            throw new Exception("Could not determine {$terminal} window ID. Is the terminal running and visible?{$debug}");
        }

        // Check if screencapture command is available
        if (shell_exec('which screencapture') === null) {
            $this->restoreTerminal();
            throw new Exception('screencapture command not found.');
        }

        // Run screencapture
        retry(times: 3, callback: function () use ($windowId, $filename) {
            exec('screencapture -l ' . escapeshellarg($windowId) . ' -o -x ' . escapeshellarg($filename), $output,
                $result);

            if ($result !== 0) {
                throw new Exception("Screencapture failed!\n" . implode(PHP_EOL, $output));
            }
        });

        // Crop off the top bar, as it causes false positives
        $cropHeight = $this->getTerminalTitleBarHeight();
        exec(sprintf('convert %s -gravity North -chop 0x%d %s', escapeshellarg($filename), $cropHeight, escapeshellarg($filename)));

        $this->restoreTerminal();
    }

    protected ?string $lastWindowIdDebugInfo = null;
    protected static ?string $cachedWindowId = null;

    /**
     * Get the window ID for the current terminal.
     */
    protected function getTerminalWindowId(): string
    {
        if (self::$cachedWindowId !== null) {
            return self::$cachedWindowId;
        }

        $terminal = $this->detectTerminal();

        // Ghostty doesn't expose window ID via AppleScript, so we use CGWindowList via Swift
        // We get the frontmost Ghostty window by using .optionOnScreenOnly (returns front-to-back order)
        // and matching the window that belongs to the frontmost app or has focus
        $ghosttySwift = <<<'SWIFT'
import Foundation
import CoreGraphics
import AppKit

// Get the frontmost Ghostty window (the one running this process)
// First, find our terminal's window by checking which Ghostty window is key/main
let options = CGWindowListOption(arrayLiteral: .optionOnScreenOnly)
if let windowList = CGWindowListCopyWindowInfo(options, kCGNullWindowID) as NSArray? as? [[String: Any]] {
    // Windows are returned front-to-back, so first matching Ghostty window at layer 0 is frontmost
    for window in windowList {
        if let owner = window["kCGWindowOwnerName"] as? String, owner.lowercased() == "ghostty",
           let layer = window["kCGWindowLayer"] as? Int, layer == 0,
           let id = window["kCGWindowNumber"] as? Int {
            print(id)
            break
        }
    }
}
SWIFT;

        $command = match ($terminal) {
            'iterm' => "osascript -e 'tell application \"iTerm\" to get the id of window 1'",
            'ghostty' => 'swift -e ' . escapeshellarg($ghosttySwift),
            default => null,
        };

        if ($command === null) {
            $this->lastWindowIdDebugInfo = "No command for terminal: " . ($terminal ?? 'null');
            return '';
        }

        $debugLines = [];
        $debugLines[] = "Terminal: {$terminal}";
        $debugLines[] = "Command: {$command}";

        // Retry a few times - AppleScript can be flaky, especially with Ghostty
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // Capture both stdout and stderr
            $output = [];
            $exitCode = 0;
            exec($command . ' 2>&1', $output, $exitCode);
            $windowId = trim(implode("\n", $output));

            $debugLines[] = "Attempt {$attempt}: exit={$exitCode}, output='{$windowId}'";

            if ($exitCode === 0 && !empty($windowId) && $windowId !== 'missing value') {
                $this->lastWindowIdDebugInfo = null;
                self::$cachedWindowId = $windowId;
                return $windowId;
            }

            if ($attempt < 3) {
                usleep(500_000); // 500ms between retries
            }
        }

        // Gather additional debug info
        $runningApps = shell_exec("osascript -e 'tell application \"System Events\" to get name of every process' 2>&1");
        $debugLines[] = "Running apps (filtered): " . (
            preg_match('/ghostty|iterm/i', $runningApps ?? '') 
                ? preg_replace('/.*?(ghostty|iterm).*?/i', '$1, ', $runningApps) 
                : 'neither found'
        );

        $this->lastWindowIdDebugInfo = implode("\n", $debugLines);

        return '';
    }

    /**
     * Activate (bring to front) the current terminal window.
     * Clears the cached window ID so we capture the correct frontmost window.
     */
    protected function activateTerminalWindow(): void
    {
        $terminal = $this->detectTerminal();

        // Clear cache so we get the fresh frontmost window
        self::$cachedWindowId = null;

        if ($terminal === 'iterm') {
            shell_exec("osascript -e 'tell application \"iTerm\" to activate'");
        } elseif ($terminal === 'ghostty') {
            shell_exec("osascript -e 'tell application \"Ghostty\" to activate'");
        }
    }

    /**
     * Get the height of the title bar to crop from screenshots.
     * Different terminals may have different title bar heights.
     */
    protected function getTerminalTitleBarHeight(): int
    {
        $terminal = $this->detectTerminal();

        // These values may need adjustment based on your system/theme
        return match ($terminal) {
            'iterm' => 60,
            'ghostty' => 30, // Ghostty has a smaller title bar
            default => 60,
        };
    }

    /**
     * Restore terminal styles and show the cursor.
     */
    protected function restoreTerminal(): void
    {
        echo "\ec"; // Brute force reset of terminal.
    }

    protected function screenshotPath(string $suffix): string
    {
        [$path, $function] = $this->uniqueTestIdentifier;
        $terminal = $this->detectTerminal() ?? 'unknown';

        return "tests/Screenshots/{$terminal}/{$path}/{$function}_{$suffix}.png";
    }

    protected function fixturePath(): string
    {
        [$path, $function] = $this->uniqueTestIdentifier;
        $terminal = $this->detectTerminal();

        // When running screenshot tests, use terminal-specific fixtures
        if ($terminal && (getenv('ENABLE_SCREENSHOT_TESTING') === '1' || getenv('ENABLE_SCREENSHOT_TESTING') === '2')) {
            return "tests/Fixtures/{$terminal}/{$path}/{$function}.json";
        }

        // For non-screenshot tests, check terminal-specific fixture first, then fall back to legacy location
        if ($terminal) {
            $terminalPath = "tests/Fixtures/{$terminal}/{$path}/{$function}.json";
            if (file_exists($terminalPath)) {
                return $terminalPath;
            }
        }

        // Fall back to legacy fixture path (for backward compatibility)
        // The $path already includes the directory structure like "Unit/MultibyteTest"
        return "tests/Fixtures/{$path}/{$function}.json";
    }

    /**
     * Compare two screenshots, ensuring they are identical within the terminal's display area.
     *
     * @throws Exception
     */
    protected function terminalAreaIsIdentical(string $term, string $emulated): bool
    {
        $diff = $this->screenshotPath('diff');

        if (shell_exec('which compare') === null) {
            throw new Exception('The `compare` tool (ImageMagick) is not installed or not in PATH.');
        }

        // Compare images and capture difference count
        $diffResult = shell_exec(sprintf('compare -metric AE %s %s %s 2>&1',
            escapeshellarg($term),
            escapeshellarg($emulated),
            escapeshellarg($diff),
        ));

        $matched = trim((string) $diffResult) === '0';

        if ($matched) {
            @unlink($term);
            @unlink($emulated);
            @unlink($diff);
        }

        return $matched;
    }

    /**
     * Create and return a Screen object matching the terminal's dimensions.
     */
    protected function makeIdenticalScreen(): Screen
    {
        $terminal = new Terminal;

        (new ReflectionClass($terminal))->getMethod('initDimensions')->invoke($terminal);

        return new Screen($terminal->getWidth(), $terminal->getHeight());
    }

    /**
     * Offer to resize the current terminal to the required dimensions.
     *
     * @return bool True if resize was attempted, false otherwise.
     */
    protected function offerToResizeTerminal(int $lines, int $columns): bool
    {
        // Only works on macOS
        if (PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        $terminal = $this->detectTerminal();

        if (!$terminal) {
            return false;
        }

        // Check if we've already asked (store in static to only ask once per test run)
        static $userResponse = null;

        if ($userResponse === false) {
            return false;
        }

        if ($userResponse === null) {
            echo "\n";
            echo "Terminal dimensions don't match CI (need {$columns}x{$lines}).\n";
            echo "Would you like to resize {$terminal} automatically? [Y/n] ";

            $handle = fopen('php://stdin', 'r');
            $input = trim(fgets($handle));
            fclose($handle);

            $userResponse = $input === '' || strtolower($input) === 'y';

            if (!$userResponse) {
                return false;
            }
        }

        return $this->resizeTerminal($terminal, $lines, $columns);
    }

    /**
     * Resize the specified terminal to the given dimensions.
     */
    protected function resizeTerminal(string $terminal, int $lines, int $columns): bool
    {
        if ($terminal === 'iterm') {
            return $this->resizeIterm($lines, $columns);
        }

        if ($terminal === 'ghostty') {
            return $this->resizeGhostty($lines, $columns);
        }

        return false;
    }

    /**
     * Resize iTerm to the required dimensions via AppleScript.
     */
    protected function resizeIterm(int $lines, int $columns): bool
    {
        $script = sprintf(
            'tell application "iTerm2"
                tell current session of current window
                    set columns to %d
                    set rows to %d
                end tell
            end tell',
            $columns,
            $lines
        );

        exec('osascript -e ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        if ($exitCode === 0) {
            usleep(100000); // 100ms
            echo "iTerm resized to {$columns}x{$lines}.\n";

            return true;
        }

        echo "Failed to resize iTerm.\n";

        return false;
    }

    /**
     * Resize Ghostty to the required dimensions via AppleScript.
     *
     * Note: Ghostty doesn't expose direct row/column control via AppleScript,
     * so we resize the window based on estimated character cell size.
     */
    protected function resizeGhostty(int $lines, int $columns): bool
    {
        // First, try to get current window bounds to calculate cell size
        // Then resize based on desired columns/rows
        // Ghostty's AppleScript support is more limited than iTerm's

        // Method 1: Use SIGWINCH-triggering resize via window bounds
        // We estimate cell size based on common defaults (may need adjustment)
        $cellWidth = 9;  // Approximate pixels per character
        $cellHeight = 20; // Approximate pixels per line

        // Add padding for window chrome
        $windowWidth = ($columns * $cellWidth) + 20;
        $windowHeight = ($lines * $cellHeight) + 50;

        $script = sprintf(
            'tell application "Ghostty"
                set bounds of window 1 to {100, 100, %d, %d}
            end tell',
            100 + $windowWidth,
            100 + $windowHeight
        );

        exec('osascript -e ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        if ($exitCode === 0) {
            usleep(200000); // 200ms - give Ghostty more time to resize
            echo "Ghostty window resized (target: {$columns}x{$lines}).\n";
            echo "Note: Ghostty resize is approximate. Please verify dimensions.\n";

            return true;
        }

        // Method 2: Try using System Events as a fallback
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
            echo "Ghostty window resized via System Events (target: {$columns}x{$lines}).\n";

            return true;
        }

        echo "Failed to resize Ghostty. Please resize manually to {$columns}x{$lines}.\n";

        return false;
    }

    /**
     * Assert that rendered output appears correct, using a fixture-based workflow.
     *
     * If a fixture exists, compares the output against it.
     * If no fixture exists, shows the output to the user and asks if it looks correct.
     * If confirmed, saves the output as a fixture for future comparisons.
     *
     * @param  string  $output  The rendered output to verify.
     */
    public function appearsToRenderCorrectly(string $output): void
    {
        $this->uniqueTestIdentifier = $this->uniqueTestIdentifier();

        $fixturePath = $this->renderFixturePath();

        if (file_exists($fixturePath)) {
            $fixture = json_decode(file_get_contents($fixturePath), true);
            $this->assertEquals($fixture['output'], $output, 'Output does not match saved fixture.');
            return;
        }

        $this->withOutputEnabled(function () use ($output, $fixturePath) {
            $this->promptAndSaveRenderFixture($output, $fixturePath);
        });
    }

    protected function renderFixturePath(): string
    {
        [$path, $function] = $this->uniqueTestIdentifier;

        $terminal = $this->detectTerminal();

        if ($terminal) {
            return "tests/Fixtures/Renders/{$terminal}/{$path}/{$function}.json";
        }

        // Fallback for unknown terminals (shouldn't happen in normal testing)
        return "tests/Fixtures/Renders/{$path}/{$function}.json";
    }

    protected function promptAndSaveRenderFixture(string $output, string $fixturePath): void
    {
        echo "\n";
        echo "═══════════════════════════════════════════════════════════════════════════════\n";
        echo "  No fixture exists for this test. We will show the rendered output.\n";
        echo "  After reviewing, press any key to continue.\n";
        echo "═══════════════════════════════════════════════════════════════════════════════\n";
        echo "  Press any key to show the output...\n";

        $this->waitForKeypress();

        $this->restoreTerminal();

        echo "\e[0m"; // Reset styles
        echo "\e[H"; // Move cursor home
        echo "\e[2J"; // Clear screen
        echo "\e[?25l"; // Hide cursor

        echo $output;

        $this->waitForKeypress();

        $this->restoreTerminal();

        echo "\n";
        echo "Does the output look correct? [Y/n] ";

        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle));
        fclose($handle);

        $confirmed = $input === '' || strtolower($input) === 'y';

        if ($confirmed) {
            $this->ensureDirectoriesExist($fixturePath);

            file_put_contents($fixturePath, json_encode([
                'output' => $output,
            ], JSON_PRETTY_PRINT));

            echo "Fixture saved to: {$fixturePath}\n";
            $this->assertTrue(true);
        } else {
            $this->fail('User indicated the output does not look correct.');
        }
    }

    protected function waitForKeypress(): void
    {
        system('stty cbreak -echo');
        fgetc(STDIN);
        system('stty sane');
    }
}
