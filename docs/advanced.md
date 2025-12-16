---
title: Advanced Usage
description: Differential rendering, screen composition, and performance optimization.
---

# Advanced Usage

This guide covers advanced topics: differential rendering for performance, composing multiple screens, and optimization techniques.

## Differential Rendering

Differential rendering is Screen's most powerful optimization. Instead of re-rendering the entire screen every frame, you only render what changed.

### The Problem

In a TUI running at 40 FPS (like Solo), full rendering every frame is expensive:

```
80 columns × 24 rows = 1,920 cells
× 40 FPS = 76,800 cell operations per second
```

Most frames, only a few lines change. Why re-render everything?

### The Solution

Screen tracks changes using sequence numbers:

```php
$screen = new Screen(80, 24);

// Initial render
$screen->write("Initial content...");
$lastSeqNo = $screen->getSeqNo();
$output = $screen->output();
echo $output;

// Later, after some changes
$screen->write("\e[5;1HUpdated line 5");

// Differential render - only changed lines
$output = $screen->output($lastSeqNo);
echo $output;

// Save new sequence number
$lastSeqNo = $screen->getSeqNo();
```

### How Sequence Numbers Work

```php
$screen = new Screen(80, 24);
// seqNo = 0

$screen->write("Line 1\n");
// seqNo = 1, lineSeqNos[0] = 1

$screen->write("Line 2\n");
// seqNo = 2, lineSeqNos[1] = 2

$screen->write("\e[1;1HUpdated");
// seqNo = 3, lineSeqNos[0] = 3 (line 0 changed again)
```

When you call `output($sinceSeqNo)`:
- Lines where `lineSeqNo > $sinceSeqNo` are rendered
- Other lines are skipped
- Cursor is positioned absolutely for each changed line

### Performance Impact

Benchmarks show 80x+ improvement for incremental updates:

| Scenario | Full Render | Differential |
|----------|-------------|--------------|
| 1 line changed | 100% | ~4% |
| 5 lines changed | 100% | ~20% |
| Cursor blink | 100% | ~0.5% |

### Best Practices

```php
class TuiApp {
    private Screen $screen;
    private int $lastSeqNo = 0;

    public function render(): void
    {
        // First frame: full render
        if ($this->lastSeqNo === 0) {
            echo $this->screen->output();
            $this->lastSeqNo = $this->screen->getSeqNo();
            return;
        }

        // Subsequent frames: differential
        $output = $this->screen->output($this->lastSeqNo);

        // Only write if there are changes
        if ($output !== '') {
            echo $output;
            $this->lastSeqNo = $this->screen->getSeqNo();
        }
    }
}
```

## Screen Composition

Screen's output can be rendered at any position on your terminal, enabling multi-panel layouts.

### Basic Composition

```php
// Create panels
$sidebar = new Screen(20, 24);
$main = new Screen(60, 24);

// Write to panels
$sidebar->write("Menu Item 1\n");
$sidebar->write("Menu Item 2\n");

$main->write("Main content here...");

// Render sidebar at column 1
echo "\e[1;1H";
echo $sidebar->output();

// Render main at column 21
echo "\e[1;21H";
echo $main->output();
```

### The Relative Positioning Solution

Screen uses DECSC/DECRC with relative cursor movement instead of absolute positioning between lines:

```
DECSC (save at panel origin)
[render line 0]
DECRC + CUD 0 (restore, down 0)
[render line 1]
DECRC + CUD 1 (restore, down 1)
...
```

This approach:
- Works regardless of where you position the screen
- Avoids the "pending wrap" problem across terminals
- Enables true composability

### Multi-Panel Layout

```php
class Dashboard {
    private Screen $header;
    private Screen $sidebar;
    private Screen $content;
    private Screen $footer;

    public function __construct(int $width, int $height)
    {
        // Layout: header (2 rows), footer (1 row), sidebar (20 cols)
        $this->header = new Screen($width, 2);
        $this->footer = new Screen($width, 1);
        $this->sidebar = new Screen(20, $height - 3);
        $this->content = new Screen($width - 20, $height - 3);
    }

    public function render(): void
    {
        // Clear screen once
        echo "\e[2J\e[H";

        // Header at top
        echo "\e[1;1H" . $this->header->output();

        // Sidebar below header
        echo "\e[3;1H" . $this->sidebar->output();

        // Content next to sidebar
        echo "\e[3;21H" . $this->content->output();

        // Footer at bottom
        echo "\e[" . ($this->getHeight()) . ";1H" . $this->footer->output();
    }
}
```

## CellBuffer for Comparison

For advanced differential rendering, you can convert Screen state to a `CellBuffer`:

```php
use SoloTerm\Screen\Buffers\CellBuffer;

// Save current state
$previousBuffer = $screen->toCellBuffer();

// ... content changes ...

// Compare states
$currentBuffer = $screen->toCellBuffer();

for ($row = 0; $row < $screen->height; $row++) {
    if (!$currentBuffer->rowEquals($row, $previousBuffer)) {
        // Row changed, render it
        echo "\e[" . ($row + 1) . ";1H";
        echo $currentBuffer->renderRow($row);
    }
}
```

### Row Hashing

CellBuffer uses polynomial rolling hashes for O(1) row comparison:

```php
// Get hash of a row
$hash = $buffer->getRowHash(5);

// Compare rows between buffers
if ($buffer1->getRowHash(5) === $buffer2->getRowHash(5)) {
    // Rows are (probably) identical
    // Hash collision is theoretically possible but extremely rare
}
```

## Query Handling

Some applications need to respond to terminal queries. Screen supports a callback for this:

```php
$screen->respondToQueriesVia(function (string $query) {
    // DSR (Device Status Report) - cursor position query
    if ($query === "\e[6n") {
        return "\e[{$row};{$col}R";
    }

    // Unknown query
    return null;
});
```

This is an advanced feature for terminal emulator integration.

## Memory Optimization

### Buffer Trimming

For long-running applications with scrolling content:

```php
// After scrolling, trim off-screen rows
$linesScrolled = $screen->linesOffScreen;
if ($linesScrolled > 1000) {
    // Trim old content to prevent memory growth
    $screen->printable->trim(0, $linesScrolled - 100);
    $screen->ansi->trim(0, $linesScrolled - 100);
}
```

### Clearing vs Overwriting

Overwriting content is more efficient than clearing first:

```php
// Less efficient
$screen->write("\e[2K");  // Clear line
$screen->write("New content");

// More efficient
$screen->write("\e[1G");  // Move to column 1
$screen->write("New content\e[K");  // Write + clear remainder
```

## Custom Rendering

### Direct Buffer Access

Access buffers directly for custom rendering:

```php
// Read character at position
$char = $screen->printable->lines[0][5] ?? ' ';

// Read style at position
$style = $screen->ansi->lines[0][5] ?? 0;

// Check if row exists
if (isset($screen->printable->lines[$row])) {
    // Process row
}
```

### Building Custom Output

```php
function renderToHtml(Screen $screen): string
{
    $html = '<pre>';

    for ($row = 0; $row < $screen->height; $row++) {
        for ($col = 0; $col < $screen->width; $col++) {
            $char = $screen->printable->lines[$row][$col] ?? ' ';
            $style = $screen->ansi->lines[$row][$col] ?? 0;

            if ($char === null) continue; // Wide char continuation

            $html .= ansiStyleToHtml($char, $style);
        }
        $html .= "\n";
    }

    return $html . '</pre>';
}
```

## Performance Tips

### 1. Batch Writes

```php
// Slower: many small writes
foreach ($lines as $line) {
    $screen->write($line . "\n");
}

// Faster: one large write
$screen->write(implode("\n", $lines));
```

### 2. Minimize Style Changes

```php
// Slower: style per word
$screen->write("\e[31mRed\e[0m \e[31mtext\e[0m \e[31mhere\e[0m");

// Faster: one style region
$screen->write("\e[31mRed text here\e[0m");
```

### 3. Use Differential Rendering

Always use differential rendering for animation loops:

```php
while ($running) {
    $this->update();

    $output = $screen->output($lastSeqNo);
    if ($output !== '') {
        echo $output;
        $lastSeqNo = $screen->getSeqNo();
    }

    usleep(25000); // 40 FPS
}
```

### 4. Avoid Unnecessary Clears

```php
// Slow: clear everything each frame
$screen->write("\e[2J\e[H");
$screen->write($content);

// Fast: just overwrite what changed
$screen->write("\e[1;1H");
$screen->write($updatedLine . "\e[K");
```

## Real-World Example: Animation Loop

```php
class AnimatedDisplay {
    private Screen $screen;
    private int $lastSeqNo = 0;
    private int $frame = 0;

    public function __construct()
    {
        $this->screen = new Screen(80, 24);
    }

    public function run(): void
    {
        // Hide cursor during animation
        echo "\e[?25l";

        while (true) {
            $this->update();
            $this->render();

            usleep(50000); // 20 FPS

            if ($this->shouldStop()) break;
        }

        // Show cursor when done
        echo "\e[?25h";
    }

    private function update(): void
    {
        $this->frame++;

        // Only update the parts that change
        $this->screen->write("\e[1;1H");
        $this->screen->write(sprintf("Frame: %d", $this->frame));

        // Animate a progress indicator
        $spinner = ['|', '/', '-', '\\'][$this->frame % 4];
        $this->screen->write("\e[1;20H");
        $this->screen->write($spinner);
    }

    private function render(): void
    {
        // Differential render
        $output = $this->screen->output($this->lastSeqNo);

        if ($output !== '') {
            echo $output;
            $this->lastSeqNo = $this->screen->getSeqNo();
        }
    }
}
```

## Next Steps

- [API Reference](api-reference) - Complete API documentation
- [Architecture](architecture) - Deep dive into internals
