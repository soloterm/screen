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
use SoloTerm\Screen\Screen;

class DifferentialRenderingTest extends TestCase
{
    #[Test]
    public function sequence_numbers_increment_on_write(): void
    {
        $screen = new Screen(80, 24);

        $initialSeqNo = $screen->getSeqNo();
        $this->assertEquals(0, $initialSeqNo);

        $screen->write('Hello');
        $afterFirstWrite = $screen->getSeqNo();
        $this->assertGreaterThan($initialSeqNo, $afterFirstWrite);

        $screen->write(' World');
        $afterSecondWrite = $screen->getSeqNo();
        $this->assertGreaterThan($afterFirstWrite, $afterSecondWrite);
    }

    #[Test]
    public function full_output_returns_all_lines(): void
    {
        $screen = new Screen(80, 24);
        $screen->write("Line 1\nLine 2\nLine 3");

        $output = $screen->output();

        $this->assertStringContainsString('Line 1', $output);
        $this->assertStringContainsString('Line 2', $output);
        $this->assertStringContainsString('Line 3', $output);
    }

    #[Test]
    public function differential_output_returns_only_changed_lines(): void
    {
        $screen = new Screen(80, 24);

        // Initial write
        $screen->write("Line 1\nLine 2\nLine 3");
        $screen->output(); // Render and get seqNo
        $seqNoAfterFirstRender = $screen->getLastRenderedSeqNo();

        // Change only line 2
        $screen->write("\033[2;1HModified Line 2");

        // Get differential output
        $diffOutput = $screen->output($seqNoAfterFirstRender);

        // Should contain modified line
        $this->assertStringContainsString('Modified Line 2', $diffOutput);

        // Should contain cursor positioning (ESC[row;colH)
        $this->assertStringContainsString("\033[2;1H", $diffOutput);

        // Should NOT contain unchanged lines in their entirety
        // (Line 1 and Line 3 shouldn't be re-rendered)
        $this->assertStringNotContainsString("Line 1\n", $diffOutput);
    }

    #[Test]
    public function differential_output_empty_when_no_changes(): void
    {
        $screen = new Screen(80, 24);

        $screen->write("Line 1\nLine 2");
        $screen->output();
        $seqNo = $screen->getLastRenderedSeqNo();

        // No changes made
        $diffOutput = $screen->output($seqNo);

        $this->assertEquals('', $diffOutput);
    }

    #[Test]
    public function last_rendered_seqno_updates_on_output(): void
    {
        $screen = new Screen(80, 24);

        $this->assertEquals(0, $screen->getLastRenderedSeqNo());

        $screen->write('Hello');
        $screen->output();

        $this->assertGreaterThan(0, $screen->getLastRenderedSeqNo());
        $this->assertEquals($screen->getSeqNo(), $screen->getLastRenderedSeqNo());
    }

    #[Test]
    public function differential_output_handles_multiple_changed_lines(): void
    {
        $screen = new Screen(80, 24);

        $screen->write("Line 1\nLine 2\nLine 3\nLine 4\nLine 5");
        $screen->output();
        $seqNo = $screen->getLastRenderedSeqNo();

        // Modify lines 2 and 4
        $screen->write("\033[2;1HChanged 2");
        $screen->write("\033[4;1HChanged 4");

        $diffOutput = $screen->output($seqNo);

        $this->assertStringContainsString('Changed 2', $diffOutput);
        $this->assertStringContainsString('Changed 4', $diffOutput);
        $this->assertStringContainsString("\033[2;1H", $diffOutput);
        $this->assertStringContainsString("\033[4;1H", $diffOutput);
    }

    #[Test]
    public function differential_output_clears_to_end_of_line(): void
    {
        $screen = new Screen(80, 24);

        $screen->write('Hello World');
        $screen->output();
        $seqNo = $screen->getLastRenderedSeqNo();

        // Write shorter content
        $screen->write("\033[1;1HHi");

        $diffOutput = $screen->output($seqNo);

        // Should contain clear-to-end-of-line escape sequence
        $this->assertStringContainsString("\033[K", $diffOutput);
    }

    #[Test]
    public function clear_operations_mark_lines_dirty(): void
    {
        $screen = new Screen(80, 24);

        $screen->write("Line 1\nLine 2\nLine 3");
        $screen->output();
        $seqNo = $screen->getLastRenderedSeqNo();

        // Clear line 2
        $screen->write("\033[2;1H\033[2K");

        $diffOutput = $screen->output($seqNo);

        // Should include the cleared line
        $this->assertStringContainsString("\033[2;1H", $diffOutput);
    }

    #[Test]
    public function ansi_colors_preserved_in_differential_output(): void
    {
        $screen = new Screen(80, 24);

        $screen->write("Normal text\nMore text");
        $screen->output();
        $seqNo = $screen->getLastRenderedSeqNo();

        // Write colored text to line 1
        $screen->write("\033[1;1H\033[31mRed Text\033[0m");

        $diffOutput = $screen->output($seqNo);

        // Should contain color codes
        $this->assertStringContainsString("\033[31m", $diffOutput);
        $this->assertStringContainsString('Red Text', $diffOutput);
    }

    #[Test]
    public function scroll_marks_visible_rows_dirty(): void
    {
        $screen = new Screen(80, 10);

        // Fill screen with lines
        for ($i = 1; $i <= 10; $i++) {
            $screen->write("Line $i\n");
        }
        $screen->output();
        $seqNo = $screen->getLastRenderedSeqNo();

        // Scroll up (adds new line at bottom, shifts everything up)
        $screen->write("\033[S");

        // All visible rows should be marked dirty after scroll
        $changedRows = $screen->printable->getChangedRows($seqNo);
        $this->assertNotEmpty($changedRows);
    }

    #[Test]
    public function benchmark_differential_vs_full_rendering(): void
    {
        $screen = new Screen(200, 50);

        // Fill the screen with content
        for ($i = 0; $i < 50; $i++) {
            $screen->write(str_repeat('X', 200) . "\n");
        }

        // Time full rendering
        $startFull = hrtime(true);
        for ($j = 0; $j < 100; $j++) {
            $screen->output();
        }
        $fullTime = hrtime(true) - $startFull;

        // Capture seqNo
        $seqNo = $screen->getLastRenderedSeqNo();

        // Modify just one line
        $screen->write("\033[25;1HSingle line change");

        // Time differential rendering
        $startDiff = hrtime(true);
        for ($j = 0; $j < 100; $j++) {
            // Reset the seqNo capture to simulate repeated calls
            $output = $screen->output($seqNo);
        }
        $diffTime = hrtime(true) - $startDiff;

        // Differential should be significantly faster
        // We expect at least 5x improvement for single-line changes
        $this->assertLessThan(
            $fullTime / 5,
            $diffTime,
            sprintf(
                'Differential rendering should be at least 5x faster. Full: %dns, Diff: %dns',
                $fullTime / 100,
                $diffTime / 100
            )
        );
    }

    #[Test]
    public function newline_scroll_marks_visible_rows_dirty(): void
    {
        $screen = new Screen(80, 5);

        // Fill the screen with 5 lines (viewport is full)
        $screen->write("Line 1\n");
        $screen->write("Line 2\n");
        $screen->write("Line 3\n");
        $screen->write("Line 4\n");
        $screen->write('Line 5');

        // Render and capture seqNo
        $screen->output();
        $seqNo = $screen->getLastRenderedSeqNo();

        // Now write a newline which should trigger scroll (cursor at bottom)
        // This moves to a new line, incrementing linesOffScreen
        $screen->write("\nLine 6");

        // All visible rows should be marked dirty because the viewport scrolled
        $changedRows = $screen->printable->getChangedRows($seqNo);

        // We should have at least all 5 visible rows marked dirty
        // The visible rows after scroll are indices 1-5 (rows 0 scrolled off)
        $this->assertGreaterThanOrEqual(5, count($changedRows),
            'newlineWithScroll should mark all visible rows dirty when viewport scrolls');
    }

    #[Test]
    public function newline_scroll_differential_output_includes_shifted_content(): void
    {
        $screen = new Screen(80, 5);

        // Fill with unique content per line so we can verify
        $screen->write("AAA\n");
        $screen->write("BBB\n");
        $screen->write("CCC\n");
        $screen->write("DDD\n");
        $screen->write('EEE');

        $screen->output();
        $seqNo = $screen->getLastRenderedSeqNo();

        // Write newline + new content, triggering scroll
        $screen->write("\nFFF");

        // Get differential output
        $diffOutput = $screen->output($seqNo);

        // After scroll: BBB is now row 1, CCC row 2, DDD row 3, EEE row 4, FFF row 5
        // All should be in the differential output since they've all shifted
        $this->assertStringContainsString('BBB', $diffOutput,
            'Shifted content should be re-rendered after newline scroll');
        $this->assertStringContainsString('CCC', $diffOutput);
        $this->assertStringContainsString('DDD', $diffOutput);
        $this->assertStringContainsString('EEE', $diffOutput);
        $this->assertStringContainsString('FFF', $diffOutput);
    }
}
