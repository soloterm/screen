<?php

declare(strict_types=1);

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Support;

class TerminalFixture
{
    public function __construct(
        public readonly string $checksum,
        public readonly int $width,
        public readonly int $height,
        public readonly string $output,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            checksum: $data['checksum'],
            width: $data['width'],
            height: $data['height'],
            output: $data['output'],
        );
    }

    public function toArray(): array
    {
        return [
            'checksum' => $this->checksum,
            'width' => $this->width,
            'height' => $this->height,
            'output' => $this->output,
        ];
    }
}
