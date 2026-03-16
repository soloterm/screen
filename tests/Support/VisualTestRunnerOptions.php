<?php

declare(strict_types=1);

namespace SoloTerm\Screen\Tests\Support;

final readonly class VisualTestRunnerOptions
{
    /**
     * @param  list<string>  $phpunitArgs
     */
    public function __construct(
        public bool $screenshots,
        public bool $missingOnly,
        public bool $failedOnly,
        public array $phpunitArgs,
        public ?string $requestedTerminal,
    ) {}

    public function screenshotModeRequested(): bool
    {
        return $this->screenshots || $this->missingOnly;
    }
}
