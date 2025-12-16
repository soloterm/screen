---
title: Architecture
description: Deep dive into Screen's internal architecture, buffers, and parsing.
---

# Architecture

Understanding Screen's architecture helps you use it more effectively and troubleshoot issues. This guide explains the internal design decisions and data flow.

## Overview

Screen uses a **dual-buffer architecture** with a **state machine ANSI parser**:

```
┌─────────────────────────────────────────────────────────────┐
│                        Input Stream                          │
│              "Hello \e[31mWorld\e[0m!\n"                     │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                       AnsiParser                             │
│                  (state machine, no regex)                   │
│                                                              │
│  Splits into: ["Hello ", "\e[31m", "World", "\e[0m", "!\n"] │
└─────────────────────────────────────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │                               │
              ▼                               ▼
┌─────────────────────────┐   ┌─────────────────────────┐
│    PrintableBuffer      │   │      AnsiBuffer         │
│                         │   │                         │
│  Stores characters:     │   │  Stores styles:         │
│  [H][e][l][l][o][ ]    │   │  [0][0][0][0][0][0]     │
│  [W][o][r][l][d][!]    │   │  [31][31][31][31][31][0]│
│  [\n]                   │   │  [0]                    │
└─────────────────────────┘   └─────────────────────────┘
              │                               │
              └───────────────┬───────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                      output() method                         │
│                                                              │
│  • Combines both buffers into final string                   │
│  • Optimizes cursor movement (CursorOptimizer)               │
│  • Minimizes style changes (StyleTracker)                    │
│  • Supports differential rendering                           │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
                    Final ANSI String
```

## The Dual-Buffer System

### Why Two Buffers?

Screen maintains two separate buffers instead of storing styled characters directly. This design provides several benefits:

1. **Memory Efficiency**: Style information is stored as compact bitmasks (integers) rather than strings
2. **Change Tracking**: Each buffer can track which rows changed independently
3. **Render Optimization**: Style changes can be minimized by comparing adjacent cells
4. **Flexibility**: Buffers can be inspected or modified independently

### PrintableBuffer

The `PrintableBuffer` stores visible characters:

```php
// Internal structure (simplified)
protected array $lines = [
    0 => ['H', 'e', 'l', 'l', 'o', ' ', 'W', 'o', 'r', 'l', 'd'],
    1 => ['S', 'e', 'c', 'o', 'n', 'd', ' ', 'l', 'i', 'n', 'e'],
];
```

**Key responsibilities:**

- Store characters at (row, col) positions
- Handle grapheme clusters (emoji, combining characters)
- Calculate character width (1 or 2 cells for wide characters)
- Expand tabs to 8-character stops
- Track which rows have changed (for differential rendering)

**Wide character handling:**

When a wide character (CJK, emoji) is written, it occupies two cells:

```php
// Writing "中" (Chinese character, 2 cells wide)
$lines[0][5] = '中';   // Primary cell has the character
$lines[0][6] = null;   // Continuation cell is null
```

This ensures cursor positioning remains accurate.

### AnsiBuffer

The `AnsiBuffer` stores styling information as bitmasks:

```php
// Internal structure (simplified)
protected array $lines = [
    0 => [0, 0, 0, 0, 0, 0, 256, 256, 256, 256, 256],  // 256 = red foreground
    1 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
];

protected int $active = 0;  // Current style state
```

**Bitmask system:**

Each ANSI SGR code maps to a bit position:

| Code | Meaning | Bit |
|------|---------|-----|
| 1 | Bold | 1 |
| 2 | Dim | 2 |
| 3 | Italic | 4 |
| 4 | Underline | 8 |
| 31 | Red foreground | 256 |
| 32 | Green foreground | 512 |
| 41 | Red background | 4096 |
| ... | ... | ... |

Multiple styles combine via bitwise OR:

```php
// Bold (1) + Underline (8) + Red (256) = 265
$style = 1 | 8 | 256;  // 265
```

**Extended colors:**

256-color and RGB colors can't fit in bitmasks, so they're stored separately:

```php
// Cell with extended color
$cell = [
    265,               // Base bitmask
    [5, 208],          // Extended foreground: 256-color index 208
    [2, 255, 128, 0],  // Extended background: RGB(255, 128, 0)
];
```

### Buffer Synchronization

The `Proxy` class ensures both buffers stay synchronized:

```php
// Screen has a Proxy that wraps both buffers
public Proxy $buffers;

// Operations like clear() are forwarded to both
$this->buffers->clear($row, $startCol, $endCol);
// Equivalent to:
// $this->printable->clear($row, $startCol, $endCol);
// $this->ansi->clear($row, $startCol, $endCol);
```

## ANSI Parsing

### Parser Design

Screen uses a state machine parser (`AnsiParser::parseFast()`) rather than regex. This provides:

- **2.5x faster performance** than regex-based parsing
- **Correct handling** of all escape sequence types
- **Streaming capability** (can process chunks)

### Parsing Flow

```
Input: "Hello \e[31mRed\e[0m"

1. Scan for ESC character (0x1B) using strpos()
2. Found at position 6

3. Output "Hello " as text token

4. Check byte after ESC:
   - '[' → CSI sequence (most common)
   - ']' → OSC sequence (title setting, etc.)
   - '(' or ')' → Character set
   - Other → Simple escape

5. For CSI '[':
   - Collect parameter bytes (0x30-0x3F): "31"
   - Collect intermediate bytes (0x20-0x2F): none
   - Get final byte (0x40-0x7E): "m"
   - Output CSI token: command="m", params="31"

6. Continue scanning from position 11...
```

### Token Types

The parser produces `ParsedAnsi` objects:

```php
// Text token
ParsedAnsi {
    raw: "Hello ",
    command: null,
    params: null
}

// CSI sequence
ParsedAnsi {
    raw: "\e[31m",
    command: "m",
    params: "31"
}
```

### Supported Sequences

**CSI Sequences** (`\e[...`):
- Cursor movement: A, B, C, D, E, F, G, H, I
- Erase: J (display), K (line)
- Scroll: S (up), T (down), L (insert lines)
- SGR: m (colors and styles)
- Modes: h, l (cursor visibility, etc.)

**Simple Escapes**:
- `\e7` - Save cursor (DECSC)
- `\e8` - Restore cursor (DECRC)
- `\ec` - Reset terminal

**OSC Sequences** (`\e]...`):
- Title setting and color queries

## Sequence Number Tracking

Screen uses sequence numbers to track changes for differential rendering:

```php
protected int $seqNo = 0;              // Global counter
protected int $lastRenderedSeqNo = 0;  // Last output() call
protected array $lineSeqNos = [];      // Per-line tracking
```

**How it works:**

1. Each `write()` increments `$seqNo`
2. Modified lines get their `$lineSeqNos[$row]` updated
3. `output($sinceSeqNo)` only renders lines where `$lineSeqNos[$row] > $sinceSeqNo`

```php
// Frame 1
$screen->write("Hello");          // seqNo = 1
$output1 = $screen->output();     // Full render, lastRenderedSeqNo = 1

// Frame 2
$screen->write("\e[5;1HWorld");   // seqNo = 2, only row 5 changed
$output2 = $screen->output(1);    // Only renders row 5
```

This enables **80x+ performance improvements** for incremental updates.

## Output Rendering

### The Pending Wrap Problem

Different terminals handle the "pending wrap" state differently. When a line fills exactly to the terminal width:

- **iTerm2**: A newline after a full line moves down one row
- **Ghostty**: May move down two rows or position content incorrectly

This inconsistency can cause content to appear offset by an entire row in some terminals when using `\n` between lines.

### How Screen Solves This

Screen's `output()` method uses DEC save/restore cursor (DECSC/DECRC) with cursor down (CUD) sequences instead of newlines:

```
ESC 7           Save cursor position (origin point)
[line 1 content]
ESC 8           Restore to origin
ESC [1B         Move down 1 row (CUD)
[line 2 content]
ESC 8           Restore to origin
ESC [2B         Move down 2 rows
[line 3 content]
...
```

This approach:
- **Avoids pending wrap entirely** — no `\n` characters between lines means wrap state doesn't matter
- **Uses relative positioning** — output renders correctly at any cursor position in a parent TUI
- **Works consistently** — same behavior across iTerm2, Ghostty, and other terminals

This enables Screen output to be rendered at any offset in a parent TUI (for panels, popups, etc.) without interference.

### Output Optimization

Two optimizer classes minimize output size:

**CursorOptimizer** - Chooses the shortest cursor movement sequence:

| Strategy | Bytes | When Used |
|----------|-------|-----------|
| `\r` | 1 | Moving to column 0 |
| `\n` | 1 | Down one row from column 0 |
| `\e[H` | 3 | Moving to home position |
| `\e[nA/B/C/D` | 4-6 | Small relative moves |
| `\e[r;cH` | 6-8 | Large absolute moves |

**StyleTracker** - Emits minimal style changes:

```php
// Instead of resetting and setting all styles:
// \e[0m\e[1m\e[31m\e[4m (16 bytes)

// Only emit what changed:
// \e[4m (4 bytes - just adding underline)
```

Combined, these optimizations provide **60-70% byte savings**.

## Memory Management

### Buffer Trimming

Buffers can grow as content scrolls. The `trim()` method removes rows that are no longer visible:

```php
// Remove rows that scrolled off the top
$this->printable->trim($fromRow, $toRow);
$this->ansi->trim($fromRow, $toRow);
```

### Sequence Number Cleanup

Line sequence numbers for trimmed rows are also cleaned up to prevent memory leaks in long-running applications.

## CellBuffer (Alternative)

For some use cases, Screen provides `CellBuffer`—a unified buffer storing `Cell` objects:

```php
use SoloTerm\Screen\Buffers\CellBuffer;
use SoloTerm\Screen\Cell;

$buffer = new CellBuffer(80, 24);

// Each cell contains character + style
$cell = new Cell('A', style: 1, fg: 31);
$buffer->setCell(0, 0, $cell);
```

**Advantages:**
- Simpler mental model (one buffer)
- Built-in double buffering for diffing
- Row hashing for O(1) comparison

**Used in:**
- Differential rendering comparisons
- Converting Screen state for value-based comparison

## Class Diagram

```
Screen
├── AnsiBuffer $ansi
├── PrintableBuffer $printable
├── Proxy $buffers
├── int $cursorRow, $cursorCol
├── int $width, $height
├── int $seqNo
└── methods:
    ├── write(string): self
    ├── output(?int): string
    ├── handleAnsiCode(ParsedAnsi)
    └── ...

Buffer (abstract)
├── array $lines
├── array $lineSeqNos
└── methods:
    ├── clear(row, start, end)
    ├── expand(rows)
    ├── getChangedRows(seqNo): array
    └── ...

PrintableBuffer extends Buffer
└── writeString(row, col, text): array

AnsiBuffer extends Buffer
├── int $active
├── ?array $extendedForeground
├── ?array $extendedBackground
└── methods:
    ├── addAnsiCode(int)
    ├── addAnsiCodes(...int)
    └── fillBufferWithActiveFlags(...)

Cell
├── string $char
├── int $style
├── ?int $fg, $bg
├── ?array $extFg, $extBg
└── methods:
    ├── equals(Cell): bool
    ├── getStyleTransition(?Cell): string
    └── ...
```

## Performance Characteristics

| Operation | Complexity | Notes |
|-----------|------------|-------|
| Write character | O(1) | Direct array access |
| Write string | O(n) | n = string length |
| Parse ANSI | O(n) | Single pass, no regex |
| Full render | O(rows × cols) | Must visit all cells |
| Diff render | O(changed) | Only changed rows |
| Style lookup | O(1) | Bitmask operations |

## Next Steps

- [ANSI Reference](ansi-reference) - Complete list of supported codes
- [Advanced Usage](advanced) - Differential rendering and optimization
- [API Reference](api-reference) - Full API documentation
