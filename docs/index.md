---
title: Introduction
description: A pure PHP terminal renderer that interprets ANSI escape sequences and maintains a virtual terminal buffer.
---

# Screen

Screen is a pure PHP terminal renderer that interprets ANSI escape sequences and maintains a virtual terminal buffer. It's the rendering engine that powers Solo's beautiful multi-panel terminal interface.

## The Problem

When building terminal user interfaces (TUIs) with multiple panels or windows, you face a fundamental challenge: how do you render output from different sources into specific regions of the screen without ANSI escape codes "breaking out" and corrupting other areas?

Consider a TUI with three panels:

```
┌─────────────────┬─────────────────┐
│  Panel 1        │  Panel 2        │
│  (process A)    │  (process B)    │
├─────────────────┴─────────────────┤
│  Panel 3 (logs)                   │
└───────────────────────────────────┘
```

If process A outputs `\e[2J` (clear screen), a naive implementation would clear the entire terminal—including panels 2 and 3. Screen solves this by maintaining isolated virtual buffers that can be safely composed.

## The Solution

Screen provides a virtual terminal buffer that:

1. **Interprets ANSI codes** in an isolated buffer (codes can't "escape")
2. **Maintains character and style state** separately for efficient rendering
3. **Supports differential rendering** to only update what changed
4. **Handles Unicode correctly** including wide characters and emoji
5. **Renders anywhere on screen** via relative positioning

```php
use SoloTerm\Screen\Screen;

// Create a virtual 80x24 terminal
$screen = new Screen(80, 24);

// Write content with ANSI codes (safely contained)
$screen->write("\e[31mHello, World!\e[0m");
$screen->write("\e[2J"); // Only clears THIS buffer, not your real terminal

// Render to actual terminal at any position
echo $screen->output();
```

## Key Features

### Safe ANSI Sandboxing

ANSI escape sequences are interpreted within the virtual buffer. A "clear screen" command clears only that buffer, not your actual terminal. This enables safe composition of multiple output sources.

### Dual-Buffer Architecture

Screen maintains two synchronized buffers:

- **PrintableBuffer**: Stores visible characters with proper width handling
- **AnsiBuffer**: Stores styling information as efficient bitmasks

This separation enables optimizations like differential rendering and efficient style tracking.

### High-Performance Parsing

The built-in ANSI parser uses a state machine (not regex) for 2.5x faster parsing. It handles:

- Standard colors (8 + 8 bright)
- Extended colors (256-color palette and 24-bit RGB)
- Text decorations (bold, italic, underline, etc.)
- Cursor movement and positioning
- Screen and line clearing
- Scrolling operations

### Unicode Support

Full Unicode support via the Grapheme package:

- Proper grapheme cluster handling
- Accurate width calculation (1 or 2 cells)
- Wide characters (CJK, emoji) handled correctly
- Zero-width joiners and combining characters

### Differential Rendering

Track changes between frames and only render what's different:

```php
$lastSeqNo = $screen->getSeqNo();

// ... time passes, content changes ...

// Only render changed lines
echo $screen->output($lastSeqNo);
```

This can provide 80x+ performance improvements for incremental updates.

## Use Cases

- **TUI applications**: Multi-panel layouts like Solo
- **Log viewers**: ANSI-aware log rendering with highlighting
- **Terminal emulators**: Virtual terminal state management
- **Testing**: Assert on terminal output without a real terminal
- **Output capture**: Process ANSI streams into structured data

## Quick Example

```php
use SoloTerm\Screen\Screen;

// Create a screen
$screen = new Screen(40, 10);

// Write styled content
$screen->write("\e[1;34m"); // Bold blue
$screen->write("=== Status ===\n");
$screen->write("\e[0m");    // Reset

$screen->write("CPU: ");
$screen->write("\e[32m70%\e[0m\n");  // Green

$screen->write("Memory: ");
$screen->write("\e[33m4.2GB\e[0m\n"); // Yellow

// Get rendered output
$output = $screen->output();
echo $output;
```

## Architecture Overview

```
Input String ("Hello \e[31mWorld\e[0m")
         │
         ▼
┌─────────────────────────────┐
│      AnsiParser             │
│  (state machine, no regex)  │
└─────────────────────────────┘
         │
         ├──── Text ────▶ PrintableBuffer
         │                 (characters)
         │
         └──── ANSI ────▶ AnsiBuffer
                          (styles as bitmasks)
         │
         ▼
┌─────────────────────────────┐
│    output() method          │
│  • Combines both buffers    │
│  • Optimizes cursor moves   │
│  • Minimizes style changes  │
└─────────────────────────────┘
         │
         ▼
    Final ANSI String
```

## Platform Support

- **PHP**: 8.1 or higher
- **Extensions**: mbstring (for Unicode)
- **OS**: Any (pure PHP, no system dependencies)
- **Architecture**: 64-bit (for bitmask operations)

## Next Steps

- [Installation](installation) - Get Screen set up in your project
- [Basic Usage](basic-usage) - Learn the fundamentals
- [Architecture](architecture) - Understand how it works
- [ANSI Reference](ansi-reference) - Complete ANSI code documentation
