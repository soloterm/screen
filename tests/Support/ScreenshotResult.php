<?php

declare(strict_types=1);

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Support;

class ScreenshotResult
{
    public function __construct(
        public readonly bool $matched,
        public readonly string $terminalPath,
        public readonly string $emulatedPath,
        public readonly string $diffPath,
        public readonly ?string $debugLog = null,
    ) {}

    public function cleanup(): void
    {
        if ($this->matched) {
            @unlink($this->terminalPath);
            @unlink($this->emulatedPath);
            @unlink($this->diffPath);
        }
    }
}
