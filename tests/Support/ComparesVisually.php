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

trait ComparesVisually
{
    protected array $testsPerMethod = [];

    protected ?array $uniqueTestIdentifier = null;

    private ?VisualTestConfig $visualConfig = null;

    private ?TerminalEnvironment $terminalEnv = null;

    private ?VisualFixtureStore $fixtureStore = null;

    protected function visualConfig(): VisualTestConfig
    {
        return $this->visualConfig ??= VisualTestConfig::fromEnvironment();
    }

    protected function terminalEnv(): TerminalEnvironment
    {
        return $this->terminalEnv ??= new TerminalEnvironment($this->visualConfig());
    }

    protected function fixtureStore(): VisualFixtureStore
    {
        return $this->fixtureStore ??= new VisualFixtureStore($this->visualConfig());
    }

    /**
     * Asserts that the given $content visually matches what would appear in the terminal.
     * This method takes screenshots of both the raw content rendered in the terminal and
     * an emulated version, then compares them pixel-by-pixel.
     */
    public function assertTerminalMatch(array|string $content, bool $iterate = false): void
    {
        if (is_array($content) && !$iterate) {
            $content = implode(PHP_EOL, $content);
        }

        if (is_string($content)) {
            $content = [$content];
        }

        $this->uniqueTestIdentifier = $this->uniqueTestIdentifier();
        [$path, $function] = $this->uniqueTestIdentifier;

        $fixturePath = $this->terminalFixturePath();
        $fixtureExists = $this->fixtureStore()->loadTerminalFixture($fixturePath, $content) !== null;
        $fixturesInSync = $this->fixtureStore()->fixturesAreInSync($path, $function);

        if ($this->visualConfig()->shouldRunVisualTest($fixtureExists, $fixturesInSync)) {
            $this->terminalEnv()->withOutput(fn() => $this->assertVisualMatch($content));
        } else {
            $this->assertFixtureMatch($content);
        }
    }

    protected function assertFixtureMatch(array $content): void
    {
        $fixturePath = $this->terminalFixturePath();
        $fixture = $this->fixtureStore()->loadTerminalFixture($fixturePath, $content);

        if (!$fixture) {
            $this->markTestSkipped(
                "Fixture with correct content does not exist for {$this->uniqueTestIdentifier[1]}. " .
                "Looked in {$fixturePath}"
            );
        }

        $screen = new Screen($fixture->width, $fixture->height);

        foreach ($content as $c) {
            $screen->write($c);
        }

        $this->assertEquals($fixture->output, $screen->output());
    }

    protected function assertVisualMatch(array $content): void
    {
        $config = $this->visualConfig();

        if (!$config->hasValidTerminal()) {
            $this->markTestSkipped(
                'Visual testing requires iTerm or Ghostty. Current terminal: ' .
                (getenv('TERM_PROGRAM') ?: 'unknown')
            );
        }

        $screen = $this->terminalEnv()->makeIdenticalScreen();

        foreach ($content as $c) {
            $screen->write($c);
        }

        $emulatedOutput = $screen->output();

        $session = new ScreenshotSession(
            $config,
            $this->terminalEnv(),
            $this->screenshotBasePath(),
        );

        $result = $session->compare($content, $emulatedOutput);

        if ($result->matched) {
            $this->writeFixtureFile($content, $screen);
            $result->cleanup();
        }

        $message = 'Failed asserting that screenshots are identical. Diff available at ' . $result->diffPath;
        if ($result->debugLog) {
            $message .= "\n\nDebug log:\n" . $result->debugLog;
        }

        $this->assertTrue($result->matched, $message);
    }

    protected function writeFixtureFile(array $content, Screen $screen): void
    {
        $this->terminalEnv()->ensureRequiredSize();

        $fixture = new TerminalFixture(
            checksum: $this->fixtureStore()->checksumFor($content),
            width: $screen->width,
            height: $screen->height,
            output: $screen->output(),
        );

        $this->fixtureStore()->saveTerminalFixture($this->terminalFixturePath(), $fixture);
    }

    /**
     * Assert that rendered output appears correct, using a fixture-based workflow.
     *
     * If a fixture exists, compares the output against it.
     * If no fixture exists, shows the output to the user and asks if it looks correct.
     * If confirmed, saves the output as a fixture for future comparisons.
     */
    public function appearsToRenderCorrectly(string $output): void
    {
        $this->uniqueTestIdentifier = $this->uniqueTestIdentifier();
        [$path, $function] = $this->uniqueTestIdentifier;

        $fixturePath = $this->renderFixturePath();
        $fixture = $this->fixtureStore()->loadRenderFixture($fixturePath);
        $fixturesInSync = $this->fixtureStore()->renderFixturesAreInSync($path, $function);

        $fixtureExists = $fixture !== null;

        if ($fixtureExists && $fixturesInSync) {
            $this->assertEquals($fixture->output, $output, 'Output does not match saved fixture.');
            return;
        }

        if (!$this->visualConfig()->shouldRunVisualTest($fixtureExists, $fixturesInSync)) {
            if (!$fixtureExists) {
                $this->markTestSkipped(
                    "Fixture does not exist for {$function}. Looked in {$fixturePath}"
                );
            }
            $this->markTestSkipped(
                "Fixtures are out of sync for {$function}. Run with ENABLE_SCREENSHOT_TESTING=2 to regenerate."
            );
        }

        $this->terminalEnv()->withOutput(function () use ($output, $fixturePath) {
            $prompter = new InteractiveFixturePrompter($this->terminalEnv(), $this->fixtureStore());

            if (!$prompter->promptAndSaveRenderFixture($output, $fixturePath)) {
                $this->fail('User indicated the output does not look correct.');
            }

            $this->assertTrue(true);
        });
    }

    protected function terminalFixturePath(): string
    {
        [$path, $function] = $this->uniqueTestIdentifier;

        return $this->fixtureStore()->terminalFixturePath($path, $function);
    }

    protected function renderFixturePath(): string
    {
        [$path, $function] = $this->uniqueTestIdentifier;

        return $this->fixtureStore()->renderFixturePath($path, $function);
    }

    protected function screenshotBasePath(): string
    {
        [$path, $function] = $this->uniqueTestIdentifier;

        return $this->fixtureStore()->screenshotBasePath($path, $function);
    }

    /**
     * Create and return a Screen object matching the terminal's dimensions.
     */
    protected function makeIdenticalScreen(): Screen
    {
        return $this->terminalEnv()->makeIdenticalScreen();
    }

    /**
     * Find the debug backtrace frame that called `assertTerminalMatch()`.
     *
     * @throws Exception If the caller cannot be found.
     */
    protected function uniqueTestIdentifier(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_ends_with($frame['file'] ?? '', 'ComparesVisually.php')) {
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
}
