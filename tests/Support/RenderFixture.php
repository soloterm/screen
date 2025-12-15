<?php

declare(strict_types=1);

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Support;

class RenderFixture
{
    public function __construct(
        public readonly string $output,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            output: $data['output'],
        );
    }

    public function toArray(): array
    {
        return [
            'output' => $this->output,
        ];
    }
}
