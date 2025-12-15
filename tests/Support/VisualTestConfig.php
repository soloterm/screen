<?php

declare(strict_types=1);

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Support;

class VisualTestConfig
{
    public const MODE_DISABLED = 0;

    public const MODE_ENABLED = 1;

    public const MODE_RECORD_MISSING = 2;

    private static ?self $instance = null;

    public function __construct(
        public readonly ?string $terminal,
        public readonly int $requiredLines,
        public readonly int $requiredColumns,
        public readonly string $fixturesRoot,
        public readonly string $screenshotsRoot,
        public readonly int $mode,
        public readonly int $maxAttempts = 2,
        public readonly int $settleMs = 150,
        public readonly int $titleBarHeightIterm = 60,
        public readonly int $titleBarHeightGhostty = 30,
    ) {}

    public static function fromEnvironment(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $terminal = self::detectTerminal();
        $mode = self::detectMode();

        self::$instance = new self(
            terminal: $terminal,
            requiredLines: 32,
            requiredColumns: 180,
            fixturesRoot: 'tests/Fixtures',
            screenshotsRoot: 'tests/Screenshots',
            mode: $mode,
        );

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    private static function detectTerminal(): ?string
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

    private static function detectMode(): int
    {
        $env = getenv('ENABLE_SCREENSHOT_TESTING');

        if ($env === '1') {
            return self::MODE_ENABLED;
        }

        if ($env === '2') {
            return self::MODE_RECORD_MISSING;
        }

        return self::MODE_DISABLED;
    }

    public function screenshotTestingEnabled(): bool
    {
        return $this->mode === self::MODE_ENABLED;
    }

    public function recordMissingFixtures(): bool
    {
        return $this->mode === self::MODE_RECORD_MISSING;
    }

    public function shouldRunVisualTest(bool $fixtureExists, bool $fixturesInSync = true): bool
    {
        if ($this->mode === self::MODE_ENABLED) {
            return true;
        }

        if ($this->mode === self::MODE_RECORD_MISSING && (!$fixtureExists || !$fixturesInSync)) {
            return true;
        }

        return false;
    }

    public function canRunVisualTest(): bool
    {
        return $this->mode !== self::MODE_DISABLED && $this->hasValidTerminal();
    }

    public function titleBarHeight(): int
    {
        return match ($this->terminal) {
            'iterm' => $this->titleBarHeightIterm,
            'ghostty' => $this->titleBarHeightGhostty,
            default => $this->titleBarHeightIterm,
        };
    }

    public function hasValidTerminal(): bool
    {
        return $this->terminal !== null;
    }

    public function terminalDisplayName(): string
    {
        return $this->terminal ?? 'unknown';
    }
}
