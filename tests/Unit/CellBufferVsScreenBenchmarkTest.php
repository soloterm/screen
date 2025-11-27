<?php

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Screen\Buffers\CellBuffer;
use SoloTerm\Screen\Screen;

class CellBufferVsScreenBenchmarkTest extends TestCase
{
    #[Test]
    public function benchmark_write_performance(): void
    {
        $width = 200;
        $height = 50;
        $iterations = 50;

        // Benchmark Screen writes
        $screenWriteTime = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $screen = new Screen($width, $height);
            $start = hrtime(true);
            for ($row = 0; $row < $height; $row++) {
                $line = str_repeat('X', $width);
                $screen->write("\e[" . ($row + 1) . ";1H" . $line);
            }
            $screenWriteTime += hrtime(true) - $start;
        }

        // Benchmark CellBuffer writes
        $cellBufferWriteTime = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $buffer = new CellBuffer($width, $height);
            $start = hrtime(true);
            for ($row = 0; $row < $height; $row++) {
                for ($col = 0; $col < $width; $col++) {
                    $buffer->writeChar($row, $col, 'X');
                }
            }
            $cellBufferWriteTime += hrtime(true) - $start;
        }

        // Report results
        $screenMs = $screenWriteTime / 1_000_000;
        $cellBufferMs = $cellBufferWriteTime / 1_000_000;

        echo "\n\nWrite Performance ({$iterations} iterations of {$width}x{$height} screen):\n";
        echo "  Screen:     " . number_format($screenMs, 2) . " ms\n";
        echo "  CellBuffer: " . number_format($cellBufferMs, 2) . " ms\n";
        echo "  Ratio:      " . number_format($screenMs / $cellBufferMs, 2) . "x\n";

        // CellBuffer should be faster (no ANSI parsing overhead)
        $this->assertTrue(true); // Just run the benchmark
    }

    #[Test]
    public function benchmark_render_performance(): void
    {
        $width = 200;
        $height = 50;
        $iterations = 100;

        // Setup Screen with content
        $screen = new Screen($width, $height);
        for ($row = 0; $row < $height; $row++) {
            $screen->write("\e[" . ($row + 1) . ";1H\e[31m" . str_repeat('R', $width));
        }

        // Setup CellBuffer with content
        $buffer = new CellBuffer($width, $height);
        $buffer->setStyle(0, 31, null, null, null); // Red
        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $width; $col++) {
                $buffer->writeChar($row, $col, 'R');
            }
        }

        // Benchmark Screen output
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $output = $screen->output();
        }
        $screenRenderTime = hrtime(true) - $start;

        // Benchmark CellBuffer render
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $output = $buffer->render();
        }
        $cellBufferRenderTime = hrtime(true) - $start;

        // Report results
        $screenMs = $screenRenderTime / 1_000_000;
        $cellBufferMs = $cellBufferRenderTime / 1_000_000;

        echo "\n\nRender Performance ({$iterations} iterations of {$width}x{$height} screen):\n";
        echo "  Screen:     " . number_format($screenMs, 2) . " ms\n";
        echo "  CellBuffer: " . number_format($cellBufferMs, 2) . " ms\n";
        echo "  Ratio:      " . number_format($screenMs / $cellBufferMs, 2) . "x\n";

        $this->assertTrue(true);
    }

    #[Test]
    public function benchmark_differential_render(): void
    {
        $width = 200;
        $height = 50;
        $iterations = 1000;

        // Setup CellBuffer with content and swap
        $buffer = new CellBuffer($width, $height);
        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $width; $col++) {
                $buffer->writeChar($row, $col, 'X');
            }
        }
        $buffer->swapBuffers();

        // Make small change
        $buffer->writeChar(25, 100, 'O');

        // Setup Screen with content
        $screen = new Screen($width, $height);
        for ($row = 0; $row < $height; $row++) {
            $screen->write("\e[" . ($row + 1) . ";1H" . str_repeat('X', $width));
        }
        $initialSeqNo = $screen->getSeqNo();

        // Make small change
        $screen->write("\e[26;101HO");

        // Benchmark Screen differential output
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $output = $screen->output($initialSeqNo);
        }
        $screenDiffTime = hrtime(true) - $start;

        // Benchmark CellBuffer renderDiff
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $output = $buffer->renderDiff();
        }
        $cellBufferDiffTime = hrtime(true) - $start;

        // Report results
        $screenMs = $screenDiffTime / 1_000_000;
        $cellBufferMs = $cellBufferDiffTime / 1_000_000;

        echo "\n\nDifferential Render Performance ({$iterations} iterations, single cell change):\n";
        echo "  Screen:     " . number_format($screenMs, 2) . " ms\n";
        echo "  CellBuffer: " . number_format($cellBufferMs, 2) . " ms\n";
        echo "  Ratio:      " . number_format($screenMs / $cellBufferMs, 2) . "x\n";

        // CellBuffer diff should be much faster
        $this->assertLessThan($screenMs / 2, $cellBufferMs);
    }

    #[Test]
    public function benchmark_memory_usage(): void
    {
        $width = 200;
        $height = 50;

        // Measure Screen memory
        $beforeScreen = memory_get_usage(true);
        $screen = new Screen($width, $height);
        for ($row = 0; $row < $height; $row++) {
            $screen->write("\e[" . ($row + 1) . ";1H\e[31m" . str_repeat('R', $width));
        }
        $afterScreen = memory_get_usage(true);
        $screenMemory = $afterScreen - $beforeScreen;

        // Clear reference
        unset($screen);
        gc_collect_cycles();

        // Measure CellBuffer memory
        $beforeCellBuffer = memory_get_usage(true);
        $buffer = new CellBuffer($width, $height);
        $buffer->setStyle(0, 31, null, null, null);
        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $width; $col++) {
                $buffer->writeChar($row, $col, 'R');
            }
        }
        $afterCellBuffer = memory_get_usage(true);
        $cellBufferMemory = $afterCellBuffer - $beforeCellBuffer;

        echo "\n\nMemory Usage ({$width}x{$height} screen with styling):\n";
        echo "  Screen:     " . number_format($screenMemory / 1024, 2) . " KB\n";
        echo "  CellBuffer: " . number_format($cellBufferMemory / 1024, 2) . " KB\n";

        $this->assertTrue(true);
    }
}
