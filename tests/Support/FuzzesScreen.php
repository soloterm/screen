<?php

declare(strict_types=1);

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Support;

use SoloTerm\Screen\Screen;

trait FuzzesScreen
{
    protected function assertChunkedOperationsParity(
        array $operations,
        int $width,
        int $height,
        int $seeds = 20,
        int $maxChunkSize = 7
    ): void {
        $baseline = $this->replayOperations($operations, $width, $height);

        for ($seed = 1; $seed <= $seeds; $seed++) {
            mt_srand($seed);
            $screen = $this->replayOperations($operations, $width, $height, chunkedWrites: true, maxChunkSize: $maxChunkSize);

            $this->assertSameVisibleState($baseline, $screen, "Visible state mismatch for seed {$seed}.");
            $this->assertSame($baseline->cursorRow, $screen->cursorRow, "Cursor row mismatch for seed {$seed}.");
            $this->assertSame($baseline->cursorCol, $screen->cursorCol, "Cursor col mismatch for seed {$seed}.");
            $this->assertSame($baseline->linesOffScreen, $screen->linesOffScreen, "Scroll offset mismatch for seed {$seed}.");
            $this->assertSame($baseline->width, $screen->width, "Width mismatch for seed {$seed}.");
            $this->assertSame($baseline->height, $screen->height, "Height mismatch for seed {$seed}.");
        }
    }

    protected function assertFullRenderReplayParity(array $operations, int $width, int $height): void
    {
        $source = $this->replayOperations($operations, $width, $height);
        $mirror = new Screen($width, $height);
        $mirror->write($source->output());

        $this->assertSameVisibleState($source, $mirror, 'Full render replay mismatch.');
    }

    protected function assertDifferentialReplayParity(
        array $initialOperations,
        array $updateOperations,
        int $width,
        int $height
    ): void {
        $source = $this->replayOperations($initialOperations, $width, $height);
        $mirror = $this->replayOperations($initialOperations, $width, $height);

        $source->output();
        $seqNo = $source->getLastRenderedSeqNo();

        foreach ($updateOperations as $index => $operation) {
            $this->applyOperation($source, $operation);

            if (is_array($operation) && ($operation[0] ?? null) === 'resize') {
                $mirror->resize($operation[1], $operation[2]);
            }

            $mirror->write($source->output($seqNo));
            $this->assertSameVisibleState($source, $mirror, "Differential replay mismatch after update {$index}.");
            $seqNo = $source->getLastRenderedSeqNo();
        }
    }

    protected function replayOperations(
        array $operations,
        int $width,
        int $height,
        bool $chunkedWrites = false,
        int $maxChunkSize = 7
    ): Screen {
        $screen = new Screen($width, $height);

        foreach ($operations as $operation) {
            $this->applyOperation($screen, $operation, $chunkedWrites, $maxChunkSize);
        }

        return $screen;
    }

    protected function applyOperation(
        Screen $screen,
        array|string $operation,
        bool $chunkedWrites = false,
        int $maxChunkSize = 7
    ): void {
        if (is_string($operation)) {
            if (!$chunkedWrites) {
                $screen->write($operation);

                return;
            }

            $offset = 0;
            $length = strlen($operation);

            while ($offset < $length) {
                $chunkSize = mt_rand(1, $maxChunkSize);
                $screen->write(substr($operation, $offset, $chunkSize));
                $offset += $chunkSize;
            }

            return;
        }

        if (($operation[0] ?? null) === 'resize') {
            $screen->resize($operation[1], $operation[2]);

            return;
        }
    }

    protected function assertSameVisibleState(Screen $expected, Screen $actual, string $message): void
    {
        $this->assertSame($this->screenSnapshot($expected), $this->screenSnapshot($actual), $message);
    }

    protected function screenSnapshot(Screen $screen): array
    {
        $buffer = $screen->toCellBuffer();
        $snapshot = [];

        for ($row = 0; $row < $screen->height; $row++) {
            $snapshotRow = [];

            foreach ($buffer->getRow($row) as $cell) {
                $snapshotRow[] = [
                    'char' => $cell->char,
                    'style' => $cell->style,
                    'fg' => $cell->fg,
                    'bg' => $cell->bg,
                    'extFg' => $cell->extFg,
                    'extBg' => $cell->extBg,
                ];
            }

            $snapshot[] = $snapshotRow;
        }

        return $snapshot;
    }
}
