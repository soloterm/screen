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
use SoloTerm\Screen\Tests\Support\ComparesVisually;

class VtailTest extends TestCase
{
    use ComparesVisually;

    #[Test]
    public function vtail_test()
    {
        $width = $this->makeIdenticalScreen()->width;
        $line = str_repeat('a', $width);

        $this->assertTerminalMatch($line . 'yo');
    }

    #[Test]
    public function positioning_test()
    {
        // This test verifies that Screen output uses relative positioning so it
        // can be rendered at arbitrary offsets in a TUI (like a popup).
        //
        // The Screen uses DECSC/DECRC (save/restore cursor) combined with CUD
        // (cursor down) to position each line relative to where the caller
        // placed the cursor - NOT absolute positioning like CR or CUP.

        $screen = new Screen(40, 3);
        $screen->write("Line 1\n");
        $screen->write("Line 2\n");
        $screen->write('Line 3');

        $output = $screen->output();

        // Output should start with DECSC (save cursor): ESC 7
        $this->assertStringStartsWith("\0337", $output,
            'Output should start with DECSC (ESC 7) to save cursor position');

        // Output should NOT contain CR (\r) which is absolute positioning
        $this->assertStringNotContainsString("\r", $output,
            'Output should NOT use CR (absolute positioning)');

        // Output should NOT contain CUP (CSI H) which is absolute positioning
        $this->assertDoesNotMatchRegularExpression('/\e\[\d*;\d*H/', $output,
            'Output should NOT use CUP/CSI H (absolute positioning)');

        // Output should contain DECRC (restore cursor): ESC 8
        $this->assertStringContainsString("\0338", $output,
            'Output should contain DECRC (ESC 8) to restore cursor position');

        // Output should contain CUD (cursor down): CSI n B
        $this->assertMatchesRegularExpression('/\e\[\d+B/', $output,
            'Output should contain CUD (CSI n B) for relative cursor movement');

        // Verify no newlines between content (we use CUD instead)
        // The content itself may have been processed, but the joining should not use \n
        $this->assertStringNotContainsString("Line 1\n", $output,
            'Lines should be joined with cursor movement, not newlines');
    }

    #[Test]
    public function pending_wrap_is_handled_correctly()
    {
        // This test verifies that the "pending wrap" state is handled correctly.
        // When writing exactly terminal-width characters, some terminals (Ghostty)
        // keep the cursor at the last column with pending wrap, while others (iTerm2)
        // wrap immediately. Our implementation avoids this issue entirely by using
        // save/restore cursor positioning.

        $screen = new Screen(80, 5);
        $screen->write(str_repeat('.', $screen->width) . 'yo ' . $screen->width);

        $output = $screen->output();

        // The output structure should be:
        // ESC 7 (save cursor)
        // ESC 8 (restore for line 1) + 80 dots
        // ESC 8 (restore for line 2) + CSI 1B (down 1) + "yo 80"

        $this->assertStringStartsWith("\0337", $output,
            'Output should start with DECSC');

        // Should contain the content
        $this->assertStringContainsString(str_repeat('.', 80), $output,
            'Should contain 80 dots');
        $this->assertStringContainsString('yo 80', $output,
            'Should contain "yo 80"');

        // Should use CUD to move to line 2
        $this->assertStringContainsString("\033[1B", $output,
            'Should use CUD to move down 1 row for second line');
    }

    #[Test]
    public function visual_positioning_in_popup()
    {
        // This test visually verifies that Screen output can be rendered inside
        // a popup at an arbitrary offset. Run with ENABLE_SCREENSHOT_TESTING=1
        // to see the visual output and confirm it looks correct.
        //
        // Expected: A bordered popup with Screen content inside, all properly
        // aligned regardless of terminal's pending wrap behavior.
        //
        // IMPORTANT: Content must fit in the screen without scrolling.
        // Each line here is exactly 60 chars (screen width) to test pending wrap.

        $screen = new Screen(60, 5);
        $screen->write("\e[43m");
        // Line 1: exactly 60 dots (tests pending wrap - no text after to cause scroll)
        $screen->write(str_repeat('-', $screen->width) . "\n");
        // Line 2: regular content
        $screen->write("Line 2: Regular content\n");
        // Line 3: more content
        $screen->write("Line 3: More content\n");
        // Line 4: exactly 60 equals signs (tests pending wrap again)
        $screen->write(str_repeat('=', $screen->width) . "\n");
        // Line 5: final line (no newline at end)
        $screen->write('Line 5: Final line');

        $offsetX = 8;
        $offsetY = 3;
        // Box structure: │ + space + content(60) + space + │ = 64 chars
        $boxWidth = $screen->width + 4;

        // Build the popup frame
        $rendered = "\e[H\e[2J"; // Clear screen, home cursor

        // Position for top border
        $rendered .= "\e[{$offsetY};{$offsetX}H";
        $rendered .= "\e[0;36m┌─ Popup ─" . str_repeat('─', $boxWidth - 11) . "┐\e[0m";

        // For each row of the Screen, draw left border
        for ($row = 1; $row <= $screen->height; $row++) {
            $rendered .= "\e[" . ($offsetY + $row) . ";{$offsetX}H";
            $rendered .= "\e[0;36m│\e[0m ";
        }

        // Position cursor for the Screen content (inside the popup)
        // Content starts at col 10 (after "│ " at cols 8-9)
        $rendered .= "\e[" . ($offsetY + 1) . ';' . ($offsetX + 2) . 'H';

        // Output the Screen - it will save this position and render relative to it
        $rendered .= $screen->output();

        // Reset ANSI state after Screen output to prevent style leaking into borders
        $rendered .= "\e[0m";

        // Draw right borders at the correct position
        // Content ends at col 69 (10 + 60 - 1), then space at 70, │ at 71
        // Position at col 70 to write " │"
        $rightPaddingCol = $offsetX + 2 + $screen->width; // 8 + 2 + 60 = 70
        for ($row = 1; $row <= $screen->height; $row++) {
            $rendered .= "\e[" . ($offsetY + $row) . ";{$rightPaddingCol}H";
            $rendered .= " \e[0;36m│\e[0m";
        }

        // Bottom border
        $rendered .= "\e[" . ($offsetY + $screen->height + 1) . ";{$offsetX}H";
        $rendered .= "\e[0;36m└" . str_repeat('─', $boxWidth - 2) . "┘\e[0m";

        // Instructions
        $rendered .= "\e[" . ($offsetY + $screen->height + 3) . ";{$offsetX}H";
        $rendered .= "\e[0;2mThe content above should be inside the popup borders.";
        $rendered .= "\e[" . ($offsetY + $screen->height + 4) . ";{$offsetX}H";
        $rendered .= "Row 1 and 4 have exactly 60 chars (screen width).\e[0m";

        $this->appearsToRenderCorrectly($rendered);
    }

    #[Test]
    public function visual_pending_wrap_test()
    {
        // Visual test specifically for the pending wrap issue.
        // The "yo 80" text should appear on line 2, column 1 - NOT offset to the right.

        $screen = new Screen(80, 3);
        $screen->write(str_repeat('.', $screen->width) . 'yo ' . $screen->width);

        $rendered = "\e[H\e[2J"; // Clear screen
        $rendered .= "\e[3;1H";  // Position at row 3, col 1
        $rendered .= $screen->output();

        // Add visual guide
        $rendered .= "\e[7;1H\e[0;2m";
        $rendered .= "Above: 80 dots on line 1, 'yo 80' should be at start of line 2.\n";
        $rendered .= "If 'yo 80' appears at column 80+, the pending wrap fix is broken.\e[0m";

        $this->appearsToRenderCorrectly($rendered);
    }
}
