---
title: ANSI Reference
description: Complete reference for all ANSI escape sequences supported by Screen.
---

# ANSI Reference

This is a comprehensive reference for all ANSI escape sequences supported by Screen.

## Escape Sequence Format

ANSI escape sequences start with the ESC character (`\e` or `\x1B`):

```
\e[<params><command>   CSI (Control Sequence Introducer)
\e]<params><terminator> OSC (Operating System Command)
\e<char>                Simple escape
```

## Text Styling (SGR)

SGR (Select Graphic Rendition) sequences control text appearance:

```
\e[<n>m     Single parameter
\e[<n>;<m>m Multiple parameters
```

### Text Attributes

| Code | Name | Effect | Reset |
|------|------|--------|-------|
| `0` | Reset | Reset all attributes | - |
| `1` | Bold | Bold or increased intensity | `22` |
| `2` | Dim | Decreased intensity | `22` |
| `3` | Italic | Italic text | `23` |
| `4` | Underline | Underlined text | `24` |
| `5` | Blink | Slow blink | `25` |
| `6` | Rapid Blink | Fast blink (rare) | `25` |
| `7` | Reverse | Swap foreground/background | `27` |
| `8` | Hidden | Invisible text | `28` |
| `9` | Strikethrough | Crossed-out text | `29` |

**Examples:**

```php
$screen->write("\e[1mBold\e[0m ");
$screen->write("\e[3mItalic\e[0m ");
$screen->write("\e[4mUnderline\e[0m ");
$screen->write("\e[1;3;4mAll three\e[0m");
```

### Standard Foreground Colors

| Code | Color | Bright Code | Bright Color |
|------|-------|-------------|--------------|
| `30` | Black | `90` | Bright Black (Gray) |
| `31` | Red | `91` | Bright Red |
| `32` | Green | `92` | Bright Green |
| `33` | Yellow | `93` | Bright Yellow |
| `34` | Blue | `94` | Bright Blue |
| `35` | Magenta | `95` | Bright Magenta |
| `36` | Cyan | `96` | Bright Cyan |
| `37` | White | `97` | Bright White |
| `39` | Default | - | - |

**Examples:**

```php
$screen->write("\e[31mRed text\e[0m\n");
$screen->write("\e[91mBright red text\e[0m\n");
$screen->write("\e[39mDefault color\e[0m\n");
```

### Standard Background Colors

| Code | Color | Bright Code | Bright Color |
|------|-------|-------------|--------------|
| `40` | Black | `100` | Bright Black |
| `41` | Red | `101` | Bright Red |
| `42` | Green | `102` | Bright Green |
| `43` | Yellow | `103` | Bright Yellow |
| `44` | Blue | `104` | Bright Blue |
| `45` | Magenta | `105` | Bright Magenta |
| `46` | Cyan | `106` | Bright Cyan |
| `47` | White | `107` | Bright White |
| `49` | Default | - | - |

**Examples:**

```php
$screen->write("\e[41mRed background\e[0m\n");
$screen->write("\e[44;97mWhite on blue\e[0m\n");
```

### 256-Color Mode

Extended 256-color palette:

```
\e[38;5;<n>m   Foreground color
\e[48;5;<n>m   Background color
```

| Range | Colors |
|-------|--------|
| 0-7 | Standard colors |
| 8-15 | High-intensity colors |
| 16-231 | 216 colors (6×6×6 cube) |
| 232-255 | Grayscale (24 shades) |

**Examples:**

```php
// Orange foreground (color 208)
$screen->write("\e[38;5;208mOrange text\e[0m\n");

// Pink background (color 218)
$screen->write("\e[48;5;218mPink background\e[0m\n");

// Grayscale
$screen->write("\e[38;5;240mDark gray\e[0m ");
$screen->write("\e[38;5;250mLight gray\e[0m\n");
```

### 24-Bit True Color (RGB)

Full RGB color support:

```
\e[38;2;<r>;<g>;<b>m   Foreground RGB
\e[48;2;<r>;<g>;<b>m   Background RGB
```

**Examples:**

```php
// Coral foreground
$screen->write("\e[38;2;255;127;80mCoral text\e[0m\n");

// Teal background
$screen->write("\e[48;2;0;128;128mTeal background\e[0m\n");

// Custom purple on yellow
$screen->write("\e[38;2;128;0;255;48;2;255;255;0mPurple on yellow\e[0m\n");
```

## Cursor Movement

### Absolute Positioning

| Sequence | Name | Effect |
|----------|------|--------|
| `\e[<r>;<c>H` | CUP | Move to row r, column c |
| `\e[<r>;<c>f` | HVP | Same as CUP |
| `\e[H` | Home | Move to row 1, column 1 |
| `\e[<n>G` | CHA | Move to column n |
| `\e[<n>d` | VPA | Move to row n |

**Note:** Row and column are 1-indexed in ANSI (row 1 is the top).

**Examples:**

```php
$screen->write("\e[1;1H");      // Top-left corner
$screen->write("\e[10;20H");    // Row 10, column 20
$screen->write("\e[5G");        // Column 5, same row
```

### Relative Movement

| Sequence | Name | Effect |
|----------|------|--------|
| `\e[<n>A` | CUU | Move up n rows |
| `\e[<n>B` | CUD | Move down n rows |
| `\e[<n>C` | CUF | Move forward n columns |
| `\e[<n>D` | CUB | Move back n columns |
| `\e[<n>E` | CNL | Move to beginning of line n down |
| `\e[<n>F` | CPL | Move to beginning of line n up |

**Examples:**

```php
$screen->write("\e[5A");   // Up 5 rows
$screen->write("\e[3B");   // Down 3 rows
$screen->write("\e[10C");  // Right 10 columns
$screen->write("\e[2D");   // Left 2 columns
```

### Tab Movement

| Sequence | Name | Effect |
|----------|------|--------|
| `\e[<n>I` | CHT | Move forward n tab stops |
| `\e[<n>Z` | CBT | Move back n tab stops |

## Erasing

### Erase in Display

| Sequence | Name | Effect |
|----------|------|--------|
| `\e[J` or `\e[0J` | ED | Clear from cursor to end of screen |
| `\e[1J` | ED | Clear from start of screen to cursor |
| `\e[2J` | ED | Clear entire screen |
| `\e[3J` | ED | Clear entire screen + scrollback |

**Examples:**

```php
$screen->write("\e[2J");   // Clear screen
$screen->write("\e[H");    // Move to home
$screen->write("\e[2J\e[H");  // Clear and home (common combo)
```

### Erase in Line

| Sequence | Name | Effect |
|----------|------|--------|
| `\e[K` or `\e[0K` | EL | Clear from cursor to end of line |
| `\e[1K` | EL | Clear from start of line to cursor |
| `\e[2K` | EL | Clear entire line |

**Examples:**

```php
$screen->write("\e[2K");   // Clear line
$screen->write("\e[K");    // Clear to end of line
```

## Scrolling

| Sequence | Name | Effect |
|----------|------|--------|
| `\e[<n>S` | SU | Scroll up n lines (content moves up) |
| `\e[<n>T` | SD | Scroll down n lines (content moves down) |
| `\e[<n>L` | IL | Insert n blank lines at cursor |
| `\e[<n>M` | DL | Delete n lines at cursor |

**Examples:**

```php
$screen->write("\e[5S");   // Scroll up 5 lines
$screen->write("\e[3L");   // Insert 3 blank lines
```

## Cursor Visibility

| Sequence | Name | Effect |
|----------|------|--------|
| `\e[?25h` | DECTCEM | Show cursor |
| `\e[?25l` | DECTCEM | Hide cursor |

**Examples:**

```php
$screen->write("\e[?25l");  // Hide cursor
// ... render content ...
$screen->write("\e[?25h");  // Show cursor
```

## Save/Restore Cursor

| Sequence | Name | Effect |
|----------|------|--------|
| `\e7` | DECSC | Save cursor position and attributes |
| `\e8` | DECRC | Restore cursor position and attributes |
| `\e[s` | SCP | Save cursor position (ANSI.SYS) |
| `\e[u` | RCP | Restore cursor position (ANSI.SYS) |

**Examples:**

```php
$screen->write("\e7");           // Save position
$screen->write("\e[10;10H");     // Move somewhere
$screen->write("Temporary");
$screen->write("\e8");           // Restore position
```

## Terminal Reset

| Sequence | Name | Effect |
|----------|------|--------|
| `\ec` | RIS | Full terminal reset |
| `\e[!p` | DECSTR | Soft terminal reset |

## Combining Sequences

You can combine multiple SGR codes in one sequence:

```php
// Bold + Italic + Red foreground + Yellow background
$screen->write("\e[1;3;31;43mStyled text\e[0m");

// Same as separate sequences
$screen->write("\e[1m\e[3m\e[31m\e[43mStyled text\e[0m");
```

## Special Characters

| Character | Code | Effect |
|-----------|------|--------|
| `\n` | 0x0A | Line feed (move down) |
| `\r` | 0x0D | Carriage return (move to column 0) |
| `\t` | 0x09 | Tab (move to next tab stop) |
| `\b` | 0x08 | Backspace (move left) |

Screen converts these to equivalent ANSI sequences internally.

## Unsupported Sequences

The following are parsed but not fully implemented:

- Character set selection (`\e(`, `\e)`)
- Most OSC sequences (title setting, etc.)
- Mouse tracking
- Keyboard remapping
- Some terminal modes

Unsupported sequences are safely ignored without affecting buffer state.

## Quick Reference Card

```
COLORS
───────────────────────────────────
\e[30-37m    Foreground colors
\e[40-47m    Background colors
\e[90-97m    Bright foreground
\e[100-107m  Bright background
\e[38;5;Nm   256-color foreground
\e[48;5;Nm   256-color background
\e[38;2;R;G;Bm  RGB foreground
\e[48;2;R;G;Bm  RGB background
\e[39m       Default foreground
\e[49m       Default background

STYLES
───────────────────────────────────
\e[0m   Reset all
\e[1m   Bold
\e[3m   Italic
\e[4m   Underline
\e[7m   Reverse
\e[9m   Strikethrough

CURSOR
───────────────────────────────────
\e[H         Home (1,1)
\e[r;cH      Move to row,col
\e[nA        Up n
\e[nB        Down n
\e[nC        Right n
\e[nD        Left n
\e7 / \e8    Save / Restore

ERASE
───────────────────────────────────
\e[2J   Clear screen
\e[K    Clear to end of line
\e[2K   Clear entire line

SCROLL
───────────────────────────────────
\e[nS   Scroll up n lines
\e[nT   Scroll down n lines
```

## Next Steps

- [Advanced Usage](advanced) - Differential rendering and optimization
- [API Reference](api-reference) - Full API documentation
