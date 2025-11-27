# Screen Performance Optimizations

This document summarizes the performance optimizations implemented in the Screen package.

## Overview

These optimizations target terminal rendering performance, reducing both CPU usage and bytes written to the terminal. The key improvements enable differential rendering - only updating cells that actually changed between frames.

## Components

### 1. CellBuffer (`src/Buffers/CellBuffer.php`)

A unified buffer that stores `Cell` objects in a flat array for O(1) access.

**Features:**
- Flat array indexing: `cells[y * width + x]`
- Double-buffering for frame comparison via `swapBuffers()`
- Dirty cell tracking for O(changed) change detection instead of O(all)
- Value-based comparison via `getChangedCells()`

**Key Methods:**
- `swapBuffers()` - Swap current buffer to previous for next frame comparison
- `getChangedCells()` - Get cells that differ from previous frame
- `renderDiff()` - Render only changed cells with cursor positioning
- `renderDiffOptimized()` - Render with optimized cursor/style tracking

**Benchmark:** 81x faster differential rendering vs full Screen render

### 2. AnsiParser (`src/AnsiParser.php`)

State machine ANSI escape sequence parser, replacing regex-based parsing.

**Features:**
- Uses `strpos()` to quickly find ESC characters
- Inline byte range checks for performance
- Returns lightweight `ParsedAnsi` objects

**Benchmark:** 2.5x faster than regex-based `AnsiMatcher`

### 3. ParsedAnsi (`src/ParsedAnsi.php`)

Lightweight ANSI match object without regex overhead.

**Features:**
- Parses command and params through string manipulation
- Compatible interface with `AnsiMatch`
- Minimal memory footprint

### 4. CursorOptimizer (`src/Output/CursorOptimizer.php`)

Chooses optimal cursor movement sequences to minimize bytes written.

**Strategies:**
- `\e[H` for home position (3 bytes)
- `\r` for column zero (1 byte vs 6+ for absolute)
- `\n` for down-one-from-column-zero (1 byte)
- Relative moves (`\e[C`, `\e[D`, `\e[A`, `\e[B`) when cheaper
- Absolute positioning when optimal

**Benchmark:** 67.5% byte savings on cursor movement

### 5. StyleTracker (`src/Output/StyleTracker.php`)

Tracks terminal style state to emit minimal ANSI style codes.

**Features:**
- Tracks current style, foreground, background, extended colors
- Only emits codes for attributes that actually changed
- Handles 256-color and RGB extended colors
- Uses efficient reset strategies when attributes are removed

**Benchmark:** 68.6% byte savings on style sequences

### 6. Screen.toCellBuffer() (`src/Screen.php`)

Converts Screen state to CellBuffer for value-based frame comparison.

**Features:**
- Extracts visible portion of screen to CellBuffer
- Converts AnsiBuffer bitmasks to Cell-compatible style values
- Enables integration with external differential renderers

## Usage

### Basic Differential Rendering

```php
$buffer = new CellBuffer($width, $height);

// Write frame 1
$buffer->writeChar(0, 0, 'H');
$buffer->writeChar(0, 1, 'i');
$buffer->swapBuffers();

// Write frame 2 (only 'i' -> 'o' changed)
$buffer->writeChar(0, 0, 'H');
$buffer->writeChar(0, 1, 'o');

// Get optimized diff output
$output = $buffer->renderDiffOptimized();
// Only outputs cursor move + 'o', not full "Ho"
```

### With Screen Integration

```php
$screen = new Screen($width, $height);
$screen->write("Hello World");

// Convert to CellBuffer for comparison
$cellBuffer = $screen->toCellBuffer();
```

## Test Coverage

All optimizations are covered by unit tests:

- `tests/Unit/CellBufferTest.php` - 34 tests
- `tests/Unit/AnsiParserTest.php` - Parser tests
- `tests/Unit/CursorOptimizerTest.php` - 17 tests
- `tests/Unit/StyleTrackerTest.php` - 16 tests

Run benchmarks:
```bash
./vendor/bin/phpunit --filter "Benchmark"
```
