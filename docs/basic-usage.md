---
title: Basic Usage
description: Learn the fundamentals of using Screen to render terminal content.
---

# Basic Usage

This guide covers the essential operations you'll use with Screen: creating a screen, writing content, styling text, and rendering output.

## Creating a Screen

Create a new Screen instance by specifying dimensions:

```php
use SoloTerm\Screen\Screen;

// Standard terminal size
$screen = new Screen(80, 24);

// Larger screen for more content
$screen = new Screen(120, 40);

// Small panel
$panel = new Screen(30, 10);
```

The dimensions represent columns (width) and rows (height).

## Writing Content

### Basic Text

Use the `write()` method to add content:

```php
$screen->write("Hello, World!");
```

Content is written at the current cursor position. The cursor advances as you write.

### Newlines

Include newlines to move to the next line:

```php
$screen->write("Line 1\n");
$screen->write("Line 2\n");
$screen->write("Line 3");
```

Or use `writeln()` which adds a newline automatically:

```php
$screen->writeln("Line 1");
$screen->writeln("Line 2");
$screen->writeln("Line 3");
```

### Method Chaining

The `write()` method returns the screen instance for chaining:

```php
$screen
    ->write("Name: ")
    ->write("John Doe\n")
    ->write("Email: ")
    ->write("john@example.com");
```

## Rendering Output

### Full Render

Get the complete rendered content with `output()`:

```php
$output = $screen->output();
echo $output;
```

This returns a string containing all visible content with ANSI codes for styling and cursor positioning.

### Understanding the Output

The output includes:

1. Content with styling (colors, bold, etc.)
2. Cursor positioning sequences
3. Optimized for minimal byte count

```php
$screen = new Screen(20, 3);
$screen->write("\e[32mGreen\e[0m text");

// Output includes positioning + styled content
$output = $screen->output();
```

## Text Styling

Screen fully supports ANSI SGR (Select Graphic Rendition) codes for styling.

### Colors

**Basic foreground colors:**

```php
$screen->write("\e[31mRed text\e[0m\n");
$screen->write("\e[32mGreen text\e[0m\n");
$screen->write("\e[33mYellow text\e[0m\n");
$screen->write("\e[34mBlue text\e[0m\n");
```

**Background colors:**

```php
$screen->write("\e[41mRed background\e[0m\n");
$screen->write("\e[44mBlue background\e[0m\n");
```

**Bright/bold colors:**

```php
$screen->write("\e[91mBright red\e[0m\n");
$screen->write("\e[92mBright green\e[0m\n");
```

### Text Decorations

```php
$screen->write("\e[1mBold text\e[0m\n");
$screen->write("\e[3mItalic text\e[0m\n");
$screen->write("\e[4mUnderlined text\e[0m\n");
$screen->write("\e[9mStrikethrough\e[0m\n");
```

### Combining Styles

Combine multiple styles in one sequence:

```php
// Bold + Red
$screen->write("\e[1;31mBold red\e[0m\n");

// Underline + Blue background
$screen->write("\e[4;44mUnderlined on blue\e[0m\n");

// Bold + Italic + Yellow
$screen->write("\e[1;3;33mBold italic yellow\e[0m\n");
```

### Reset

Always reset styles to prevent them from affecting subsequent text:

```php
// \e[0m resets all styles
$screen->write("\e[31mRed ");
$screen->write("still red ");
$screen->write("\e[0mnormal again");
```

## Cursor Movement

### Absolute Positioning

Move the cursor to a specific position:

```php
// Move to row 5, column 10 (1-indexed in ANSI)
$screen->write("\e[5;10H");
$screen->write("Text at position 5,10");
```

### Relative Movement

Move the cursor relative to current position:

```php
$screen->write("\e[2A");  // Up 2 rows
$screen->write("\e[3B");  // Down 3 rows
$screen->write("\e[5C");  // Right 5 columns
$screen->write("\e[4D");  // Left 4 columns
```

### Column Positioning

Move to a specific column:

```php
$screen->write("\e[10G");  // Move to column 10
```

### Using PHP Properties

You can also read cursor position directly:

```php
echo "Cursor at: {$screen->cursorRow}, {$screen->cursorCol}";
```

## Clearing Content

### Clear Entire Screen

```php
$screen->write("\e[2J");  // Clear screen
$screen->write("\e[H");   // Move cursor to home (top-left)
```

### Clear Line

```php
$screen->write("\e[2K");  // Clear entire line
$screen->write("\e[K");   // Clear from cursor to end of line
$screen->write("\e[1K");  // Clear from start of line to cursor
```

### Clear Regions

```php
$screen->write("\e[J");   // Clear from cursor to end of screen
$screen->write("\e[1J");  // Clear from start of screen to cursor
```

## Practical Examples

### Status Display

```php
$screen = new Screen(50, 8);

$screen->write("\e[1;36m┌────────────────────────────────────────────────┐\e[0m\n");
$screen->write("\e[1;36m│\e[0m          \e[1mSystem Status\e[0m                      \e[1;36m│\e[0m\n");
$screen->write("\e[1;36m├────────────────────────────────────────────────┤\e[0m\n");
$screen->write("\e[1;36m│\e[0m  CPU:    \e[32m████████░░\e[0m  78%                   \e[1;36m│\e[0m\n");
$screen->write("\e[1;36m│\e[0m  Memory: \e[33m██████░░░░\e[0m  62%                   \e[1;36m│\e[0m\n");
$screen->write("\e[1;36m│\e[0m  Disk:   \e[31m█████████░\e[0m  91%                   \e[1;36m│\e[0m\n");
$screen->write("\e[1;36m└────────────────────────────────────────────────┘\e[0m\n");

echo $screen->output();
```

### Colored Log Output

```php
$screen = new Screen(80, 20);

function writeLog(Screen $screen, string $level, string $message): void
{
    $colors = [
        'INFO'  => '34',  // Blue
        'WARN'  => '33',  // Yellow
        'ERROR' => '31',  // Red
        'DEBUG' => '90',  // Gray
    ];

    $color = $colors[$level] ?? '0';
    $time = date('H:i:s');

    $screen->write("\e[90m{$time}\e[0m ");
    $screen->write("\e[{$color}m[{$level}]\e[0m ");
    $screen->write("{$message}\n");
}

writeLog($screen, 'INFO', 'Application started');
writeLog($screen, 'DEBUG', 'Loading configuration...');
writeLog($screen, 'WARN', 'Config file missing, using defaults');
writeLog($screen, 'INFO', 'Server listening on port 8080');
writeLog($screen, 'ERROR', 'Connection refused to database');

echo $screen->output();
```

### Progress Bar

```php
function renderProgress(Screen $screen, int $current, int $total): void
{
    $width = 40;
    $percent = $current / $total;
    $filled = (int)($width * $percent);
    $empty = $width - $filled;

    $screen->write("\r"); // Return to start of line
    $screen->write("\e[K"); // Clear line

    $screen->write("Progress: [");
    $screen->write("\e[32m" . str_repeat("█", $filled) . "\e[0m");
    $screen->write(str_repeat("░", $empty));
    $screen->write("] ");
    $screen->write(sprintf("%3d%%", $percent * 100));
}

$screen = new Screen(60, 1);

for ($i = 0; $i <= 100; $i += 10) {
    renderProgress($screen, $i, 100);
    echo $screen->output();
    usleep(200000); // 200ms
}
```

## Tab Handling

Tabs are expanded to 8-character tab stops:

```php
$screen->write("Name\tAge\tCity\n");
$screen->write("John\t28\tNew York\n");
$screen->write("Jane\t32\tLos Angeles\n");
```

## Wide Characters

Screen correctly handles wide characters (CJK, emoji):

```php
$screen = new Screen(20, 5);

$screen->write("Hello: 你好\n");
$screen->write("Emoji: 🎉🎊\n");
$screen->write("Mix: A中B国C\n");

echo $screen->output();
```

Wide characters occupy 2 cells, and Screen tracks this correctly for cursor positioning.

## Next Steps

- [Architecture](architecture) - Learn how Screen works internally
- [ANSI Reference](ansi-reference) - Complete list of supported ANSI codes
- [Advanced Usage](advanced) - Differential rendering and optimization
