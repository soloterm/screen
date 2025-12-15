# The Pending Wrap Problem: A Deep Dive into Terminal Rendering Differences

When building terminal UI applications, you might assume that writing text to a terminal is straightforward. Write characters, move to the next line, repeat. But lurking beneath this simplicity is a subtle behavior called **pending wrap state** that can cause your carefully crafted TUI to render completely differently across terminals.

This post documents what we learned while fixing a rendering bug where content appeared at column 81 in Ghostty but rendered correctly in iTerm2.

## The Bug

We have a `Screen` class that acts as a virtual terminal buffer. You write content to it, and it tracks characters and ANSI styles in a grid. When you call `output()`, it produces a string you can print to the real terminal.

The bug manifested when writing exactly 80 characters (the terminal width) followed by more content:

```php
$screen = new Screen(80, 5);
$screen->write(str_repeat('.', 80) . 'yo 80');
echo $screen->output();
```

**Expected output:**
```
................................................................................
yo 80
```

**Actual output in Ghostty:**
```
................................................................................yo 80
                                                                                ↑
                                                                          (at column 81!)
```

The `yo 80` text appeared at column 81 on the first line instead of wrapping to line 2.

## What is Pending Wrap State?

When a terminal has auto-wrap mode enabled (DECAWM, which is the default), it needs to handle what happens when you print a character in the rightmost column. There are two possible behaviors:

### Behavior A: Immediate Wrap (iTerm2)
After printing the 80th character, immediately move the cursor to row 2, column 1.

### Behavior B: Pending Wrap (Ghostty, and most standards-compliant terminals)
After printing the 80th character, keep the cursor at column 80 but set an internal "pending wrap" flag. The actual wrap to the next line only happens when the *next printable character* arrives.

The pending wrap behavior exists because it allows the cursor to remain at the last column for operations like backspace or cursor movement without accidentally wrapping. It's the more "correct" VT100 behavior.

## Why This Caused Our Bug

Our original `Screen::outputFull()` method joined lines with newlines:

```php
return implode(PHP_EOL, $outputLines);
```

Here's what happens with 80 dots followed by a newline:

**In iTerm2 (immediate wrap):**
1. Print 80 dots → cursor wraps to row 2, col 1
2. Receive `\n` → cursor moves to row 3, col 1
3. Print next line starting at row 3

**In Ghostty (pending wrap):**
1. Print 80 dots → cursor at row 1, col 80, pending wrap flag SET
2. Receive `\n` → pending wrap flag CLEARED, cursor moves down one row but STAYS at col 80
3. Print next line starting at row 2, col 80 ← **Bug!**

The newline character (`\n`, LF) clears the pending wrap state and performs a line feed, but it doesn't perform a carriage return. The cursor stays at whatever column it was in.

## Failed Solution #1: CR+LF

Our first attempt was to use `\r\n` (carriage return + line feed) instead of just `\n`:

```php
return implode("\r\n", $outputLines);
```

The carriage return (`\r`) moves the cursor to column 1, clearing any pending wrap state. Then `\n` moves down a row. This works for the pending wrap issue!

**But there's a problem:** The `Screen` component is designed to be composable. You might render a `Screen` inside a popup that's positioned at column 10. Using `\r` is *absolute* positioning—it always goes to column 1 of the terminal, not column 1 of your popup. This breaks composition.

## The Solution: Save/Restore with Relative Movement

The fix is to avoid newlines entirely for line transitions. Instead, we:

1. Save the cursor position at the start (this becomes our "origin")
2. For each line, restore to the origin and move down N rows using relative cursor movement
3. Never use `\n` between lines

```php
protected function outputFull(array $ansi, array $printable): string
{
    $parts = [];

    // Save the caller's cursor position as the Screen origin (DECSC)
    $parts[] = "\0337";

    foreach ($printable as $lineIndex => $line) {
        $visibleRow = $lineIndex - $this->linesOffScreen + 1;

        if ($visibleRow < 1 || $visibleRow > $this->height) {
            continue;
        }

        // Restore to origin (DECRC)
        $parts[] = "\0338";

        // Move down to this line's row using CUD (cursor down)
        if ($visibleRow > 1) {
            $parts[] = "\033[" . ($visibleRow - 1) . "B";
        }

        // Render the line content
        $parts[] = $this->renderLine($lineIndex, $line, $ansi[$lineIndex] ?? []);
    }

    return implode('', $parts);
}
```

### Why This Works

1. **Pending wrap becomes irrelevant.** We never use `\n` to move between lines, so it doesn't matter whether the terminal has pending wrap set or not. Each line starts fresh from the saved origin.

2. **Positioning is relative.** The origin is wherever the caller positioned the cursor before calling `output()`. If that's inside a popup at column 10, all lines render relative to that position.

3. **DECRC clears pending wrap.** As a side effect, restoring the cursor position also clears any pending wrap state from the previous line, giving us a clean slate.

### The Escape Sequences

- `ESC 7` (DECSC): Save cursor position and attributes
- `ESC 8` (DECRC): Restore cursor position and attributes  
- `CSI n B` (CUD): Move cursor down n rows (relative)

## Testing Across Terminals

This experience highlighted the importance of testing TUI applications across multiple terminals. What works in one terminal may break in another due to subtle differences in VT100 interpretation.

We updated our test infrastructure to:

1. **Require visual tests in both iTerm2 and Ghostty**
2. **Store fixtures per-terminal** in `tests/Fixtures/{iterm,ghostty}/...`
3. **Validate in CI that both fixture sets exist and match**

If the fixtures don't match between terminals, it indicates a rendering difference that needs investigation.

## Key Takeaways

1. **Pending wrap state is real** and differs between terminals. Ghostty follows the VT100 spec more strictly than iTerm2.

2. **Newlines don't reset column position.** `\n` performs a line feed (move down), not a carriage return (move to column 1).

3. **Avoid absolute positioning in composable components.** Using `\r` or `CSI H` (cursor position) breaks when your component is rendered at an offset.

4. **Save/restore cursor with relative movement** is a robust pattern for multi-line output that works regardless of pending wrap state and supports composition.

5. **Test across multiple terminals.** If you only test in iTerm2, you might ship code that's broken in half your users' terminals.

## References

- [VT100 User Guide - Cursor Movement](https://vt100.net/docs/vt100-ug/chapter3.html)
- [XTerm Control Sequences](https://invisible-island.net/xterm/ctlseqs/ctlseqs.html)
- [ECMA-48 (ANSI escape codes standard)](https://www.ecma-international.org/publications-and-standards/standards/ecma-48/)
