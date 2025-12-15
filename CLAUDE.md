# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Screen is a pure PHP terminal renderer that interprets ANSI escape sequences and maintains a virtual terminal buffer. It was originally created to solve a specific problem in Solo for Laravel: preventing ANSI escape codes from "breaking out" of visual containers in a TUI with multiple panels.

Unlike a full terminal emulator, Screen focuses on rendering: it interprets ANSI codes (cursor movement, colors, styles, screen clearing), maintains a virtual representation, and generates the final visual output. It does not handle input, interactive sessions, or process management.

**Key characteristic**: This is a library meant to be integrated into PHP applications, not a standalone terminal.

## Common Commands

```bash
# Run all tests
composer test

# Run all tests with visual screenshot comparison (requires iTerm + ImageMagick)
ENABLE_SCREENSHOT_TESTING=1 composer test

# Run tests with screenshots only for missing fixtures
ENABLE_SCREENSHOT_TESTING=2 composer test

# Run a specific test
vendor/bin/phpunit --filter=test_name

# Run a specific test file
vendor/bin/phpunit tests/Unit/ScreenTest.php
```

## High-Level Architecture

### The Dual-Buffer System

Screen uses a **dual-buffer architecture** to separate concerns:

1. **PrintableBuffer**: Stores visible characters and handles grapheme cluster logic
   - Manages multi-byte characters (Unicode, emoji, CJK)
   - Calculates display widths using the Grapheme library
   - Wide characters occupy multiple cells (first cell contains char, remaining are `null`)
   - Handles tab expansion (8-character tab stops)

2. **AnsiBuffer**: Stores styling information as bitmasks
   - Uses bitwise operations for efficient style combination/manipulation
   - Each ANSI code (bold, underline, colors) gets a unique bit
   - Extended colors (256-color, RGB) stored separately as arrays
   - Cell values: either an integer (bitmask) or `[bitmask, extFg, extBg]`

The **Proxy** class coordinates writes to both buffers simultaneously, ensuring the character buffer and style buffer stay in sync.

### Three Parsing Paths

Screen has evolved to include multiple parsing strategies for different performance characteristics:

1. **AnsiMatcher** (legacy): Regex-based ANSI parsing
2. **AnsiParser**: State machine parser that processes character-by-character without regex
   - `parse()`: Returns `AnsiMatch` objects (backward compatible)
   - `parseFast()`: Returns `ParsedAnsi` objects (faster, used by Screen)
3. **ParsedAnsi**: Lightweight parsed representation with direct property access

The state machine parser (`AnsiParser::parseFast()`) is the current production path used by `Screen::write()`.

### Rendering Pipeline

When `Screen::write()` is called:

1. Content is preprocessed (backspace → cursor left, CR → column 0)
2. `AnsiParser::parseFast()` splits into printable text and ANSI sequences
3. For each part:
   - **Printable text**: Written to PrintableBuffer (handles grapheme width calculation, line wrapping)
   - **ANSI sequence**: Handled by `handleAnsiCode()` which updates cursor position or active styles
4. Both buffers track changes via sequence numbers for differential rendering

When `Screen::output()` is called:

1. **Full rendering** (default): All lines joined by newlines
2. **Differential rendering** (`output($sinceSeqNo)`): Only changed lines with cursor positioning
   - Each buffer tracks which lines were modified via sequence numbers
   - PrintableBuffer provides changed row list
   - Only those rows are compressed and rendered
   - Each line prefixed with `\033[row;1H` for positioning

### Cell-Based Architecture (Alternative Path)

Screen has two rendering approaches:

1. **Original**: Dual buffers (PrintableBuffer + AnsiBuffer) with separate compression
2. **CellBuffer**: Unified buffer storing `Cell` objects
   - Each Cell contains: `char`, `style`, `fg`, `bg`, `extFg`, `extBg`
   - Flat array indexing: `cells[$y * $width + $x]` for O(1) access
   - Supports dirty cell tracking for optimized differential rendering
   - Includes `CursorOptimizer` to minimize escape sequence bytes
   - Used via `Screen::toCellBuffer()` for value-based comparisons

The CellBuffer approach enables more sophisticated differential rendering by comparing actual cell content rather than just tracking writes.

### Differential Rendering

Screen implements performance optimizations for frequent updates:

1. **Sequence Number Tracking**: Each write increments a monotonic sequence number
   - Buffers track `lineSeqNos[$row]` = sequence when row was modified
   - `output($sinceSeqNo)` only renders rows changed since that sequence
   - Returns ANSI with cursor positioning for each changed line

2. **CellBuffer Differential Rendering**:
   - Maintains previous frame in `previousCells` array
   - Tracks dirty cells in `dirtyCells` for O(dirty) instead of O(all cells)
   - `getChangedCells()` returns only modified cells with positions
   - `renderDiffOptimized()` uses CursorOptimizer + StyleTracker for minimal output

### Coordinate System Details

Screen maintains cursor position across two coordinate spaces:

- **User space**: 0-based row/column (internal tracking)
- **ANSI space**: 1-based row/column (for escape sequences)
- **Scrolling**: `linesOffScreen` tracks how many lines have scrolled off the top
  - Cursor row calculations: `cursorRow - linesOffScreen` = visible row
  - Scrolling increments `linesOffScreen` and shifts buffer indices

### Visual Testing System

The test suite includes an innovative screenshot-based comparison system built from focused components:

- `ComparesVisually` trait — Test API facade (~200 lines)
- `VisualTestConfig` — Environment detection, dimensions, test modes
- `ScreenshotSession` — Capture and compare via Swift helper
- `VisualFixtureStore` — Fixture I/O and checksums
- `capture-window.swift` — Native screenshot capture using `CGWindowListCreateImage`

**How it works:**
1. **Capture real terminal output**: Renders content in iTerm/Ghostty, captures via `CGWindowListCreateImage`
2. **Capture emulated output**: Renders same content through Screen, captures screenshot
3. **Pixel comparison**: Uses ImageMagick to verify identical visual output

This ensures Screen's rendering matches actual terminal behavior for complex scenarios (multi-byte chars, ANSI formatting, cursor movements, scrolling).

**Fixture fallback**: Tests can run without screenshots using pre-generated fixtures (JSON files with expected output), making CI/CD possible without iTerm + ImageMagick.

### ANSI Code Handling

Screen handles these ANSI sequence categories:

- **CSI sequences**: `ESC [ params command` (cursor movement, colors, clearing)
- **Simple escapes**: `ESC command` (save/restore cursor)
- **OSC sequences**: `ESC ] ... terminator` (title, color queries)
- **Character sets**: `ESC ( X`, `ESC ) X`, `ESC # X` (mostly ignored)

Key implementations:
- **SGR (colors/styles)**: Codes parsed and applied to AnsiBuffer bitmask
- **Cursor movement**: Updates `cursorRow`/`cursorCol` with boundary checking
- **Screen clearing**: Calls `buffers->clear()` with row/column ranges
- **Scrolling**: Modifies `linesOffScreen` and shifts buffer content
- **Query responses**: `respondToQueriesVia()` callback for cursor position, colors

### Memory Management

- Buffers have a max row limit (default 5000)
- `trim()` method runs randomly (5% chance) to avoid constant overhead
- When excess rows accumulate, oldest rows are cleared
- This prevents unbounded memory growth in long-running processes
