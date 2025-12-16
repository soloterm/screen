---
title: API Reference
description: Complete API documentation for all Screen classes and methods.
---

# API Reference

Complete reference documentation for all public classes, methods, and properties.

## Screen Class

The main class for creating and managing virtual terminal buffers.

```php
use SoloTerm\Screen\Screen;
```

### Constructor

```php
public function __construct(int $width, int $height)
```

Creates a new Screen with the specified dimensions.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$width` | int | Number of columns |
| `$height` | int | Number of rows |

**Example:**

```php
$screen = new Screen(80, 24);
```

### Properties

```php
public AnsiBuffer $ansi;
public PrintableBuffer $printable;
public Proxy $buffers;
public int $cursorRow = 0;
public int $cursorCol = 0;
public int $linesOffScreen = 0;
public int $width;
public int $height;
```

| Property | Type | Description |
|----------|------|-------------|
| `$ansi` | AnsiBuffer | Buffer storing style information |
| `$printable` | PrintableBuffer | Buffer storing characters |
| `$buffers` | Proxy | Proxy for operations on both buffers |
| `$cursorRow` | int | Current cursor row (0-indexed) |
| `$cursorCol` | int | Current cursor column (0-indexed) |
| `$linesOffScreen` | int | Lines scrolled off the top |
| `$width` | int | Screen width in columns |
| `$height` | int | Screen height in rows |

### Methods

#### write()

```php
public function write(string $content): static
```

Write content to the screen at the current cursor position. Interprets ANSI escape sequences.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$content` | string | Text with optional ANSI codes |

**Returns:** `$this` for method chaining

**Example:**

```php
$screen->write("Hello ")
       ->write("\e[32mWorld\e[0m");
```

#### writeln()

```php
public function writeln(string $content): void
```

Write content followed by a newline.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$content` | string | Text with optional ANSI codes |

**Example:**

```php
$screen->writeln("Line 1");
$screen->writeln("Line 2");
```

#### output()

```php
public function output(?int $sinceSeqNo = null): string
```

Render the screen content to an ANSI string.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$sinceSeqNo` | ?int | Sequence number for differential rendering |

**Returns:** ANSI-formatted string

**Example:**

```php
// Full render
$output = $screen->output();

// Differential render (only changed lines)
$output = $screen->output($lastSeqNo);
```

#### getSeqNo()

```php
public function getSeqNo(): int
```

Get the current sequence number. Incremented on each write operation.

**Returns:** Current sequence number

#### getLastRenderedSeqNo()

```php
public function getLastRenderedSeqNo(): int
```

Get the sequence number from the last `output()` call.

**Returns:** Last rendered sequence number

#### respondToQueriesVia()

```php
public function respondToQueriesVia(Closure $closure): static
```

Set a callback for handling terminal queries.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$closure` | Closure | Callback receiving query string, returning response |

**Example:**

```php
$screen->respondToQueriesVia(function (string $query) {
    if ($query === "\e[6n") {
        return "\e[1;1R"; // Cursor position response
    }
    return null;
});
```

#### toCellBuffer()

```php
public function toCellBuffer(?CellBuffer $targetBuffer = null): CellBuffer
```

Convert the visible screen portion to a CellBuffer.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$targetBuffer` | ?CellBuffer | Optional buffer to reuse |

**Returns:** CellBuffer with current screen state

#### saveCursor()

```php
public function saveCursor(): void
```

Save the current cursor position (equivalent to ANSI ESC 7 / DECSC).

#### restoreCursor()

```php
public function restoreCursor(): void
```

Restore the previously saved cursor position (equivalent to ANSI ESC 8 / DECRC).

#### moveCursorCol()

```php
public function moveCursorCol(?int $absolute = null, ?int $relative = null): void
```

Move cursor column position.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$absolute` | ?int | Set cursor to this column (0-indexed) |
| `$relative` | ?int | Move cursor by this many columns (negative = left) |

#### moveCursorRow()

```php
public function moveCursorRow(?int $absolute = null, ?int $relative = null): void
```

Move cursor row position.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$absolute` | ?int | Set cursor to this row (0-indexed) |
| `$relative` | ?int | Move cursor by this many rows (negative = up) |

---

## Cell Class

Represents a single terminal cell with character and styling.

```php
use SoloTerm\Screen\Cell;
```

### Constructor

```php
public function __construct(
    string $char = ' ',
    int $style = 0,
    ?int $fg = null,
    ?int $bg = null,
    ?array $extFg = null,
    ?array $extBg = null
)
```

### Properties

```php
public string $char = ' ';
public int $style = 0;
public ?int $fg = null;
public ?int $bg = null;
public ?array $extFg = null;
public ?array $extBg = null;
```

| Property | Type | Description |
|----------|------|-------------|
| `$char` | string | The visible character |
| `$style` | int | Bitmask for decorations (bold=1, italic=4, etc.) |
| `$fg` | ?int | Basic foreground color (30-37, 90-97) |
| `$bg` | ?int | Basic background color (40-47, 100-107) |
| `$extFg` | ?array | Extended foreground [type, ...params] |
| `$extBg` | ?array | Extended background [type, ...params] |

### Methods

#### equals()

```php
public function equals(Cell $other): bool
```

Check if two cells are identical.

#### hasStyle()

```php
public function hasStyle(): bool
```

Check if cell has any styling.

#### isContinuation()

```php
public function isContinuation(): bool
```

Check if cell is a wide character continuation.

#### getStyleTransition()

```php
public function getStyleTransition(?Cell $previous = null): string
```

Get ANSI codes needed to transition from previous cell's style.

#### Static Factory Methods

```php
public static function blank(): Cell
public static function continuation(): Cell
```

---

## AnsiBuffer Class

Buffer for storing style information.

```php
use SoloTerm\Screen\Buffers\AnsiBuffer;
```

### Methods

#### addAnsiCode()

```php
public function addAnsiCode(int $code): void
```

Add a single ANSI SGR code to the active style.

#### addAnsiCodes()

```php
public function addAnsiCodes(int ...$codes): void
```

Add multiple ANSI SGR codes. Handles extended colors (256/RGB).

**Example:**

```php
// 256-color: 38;5;208
$buffer->addAnsiCodes(38, 5, 208);

// RGB: 38;2;255;128;0
$buffer->addAnsiCodes(38, 2, 255, 128, 0);
```

#### getActive()

```php
public function getActive(): int
```

Get the current active style bitmask.

#### fillBufferWithActiveFlags()

```php
public function fillBufferWithActiveFlags(int $row, int $start, int $end): void
```

Apply the current active style to a region.

#### clear()

```php
public function clear(int $row, int $startCol, int $endCol): void
```

Clear styling in a region (reset to 0).

---

## PrintableBuffer Class

Buffer for storing visible characters.

```php
use SoloTerm\Screen\Buffers\PrintableBuffer;
```

### Methods

#### setWidth()

```php
public function setWidth(int $width): void
```

Set the buffer width for line wrapping calculations.

#### writeString()

```php
public function writeString(int $row, int $col, string $text): array
```

Write a string at the specified position.

**Returns:** `[$advance, $remainder]` - columns advanced and any overflow text

**Example:**

```php
[$advance, $overflow] = $buffer->writeString(0, 70, "Hello World");
// If width is 80: advance=10, overflow="d"
```

#### clear()

```php
public function clear(int $row, int $startCol, int $endCol): void
```

Clear characters in a region (fill with spaces).

---

## CellBuffer Class

Unified buffer storing Cell objects with built-in diffing support.

```php
use SoloTerm\Screen\Buffers\CellBuffer;
```

### Constructor

```php
public function __construct(int $width, int $height, int $maxRows = 5000)
```

### Methods

#### setCell()

```php
public function setCell(int $row, int $col, Cell $cell): void
```

Set a cell at the specified position.

#### getCell()

```php
public function getCell(int $row, int $col): Cell
```

Get the cell at the specified position.

#### writeChar()

```php
public function writeChar(int $row, int $col, string $char): void
```

Write a character with current style.

#### getRowHash()

```php
public function getRowHash(int $row): int
```

Get the polynomial rolling hash for a row.

#### rowEquals()

```php
public function rowEquals(int $row, CellBuffer $other): bool
```

Compare a row with another buffer using hashes.

#### renderRow()

```php
public function renderRow(int $row): string
```

Render a single row to an ANSI string.

#### render()

```php
public function render(): string
```

Render the entire buffer to an ANSI string.

#### getChangedRows()

```php
public function getChangedRows(int $sinceSeqNo): array
```

Get list of rows that changed since the given sequence number.

#### insertLines()

```php
public function insertLines(int $atRow, int $count): void
```

Insert blank lines at the specified row, shifting existing content down.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$atRow` | int | Row index to insert at |
| `$count` | int | Number of blank lines to insert |

#### deleteLines()

```php
public function deleteLines(int $atRow, int $count): void
```

Delete lines at the specified row, shifting content up.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$atRow` | int | Row index to delete from |
| `$count` | int | Number of lines to delete |

#### scrollUp()

```php
public function scrollUp(int $lines = 1): void
```

Scroll the buffer up by the specified number of lines.

#### scrollDown()

```php
public function scrollDown(int $lines = 1): void
```

Scroll the buffer down by the specified number of lines.

#### swapBuffers()

```php
public function swapBuffers(): void
```

Swap current buffer to previous for frame-to-frame differential comparison.

#### hasPreviousFrame()

```php
public function hasPreviousFrame(): bool
```

Check if a previous frame exists for comparison.

#### getChangedCells()

```php
public function getChangedCells(): array
```

Get array of changed cells with their positions. Returns `[[row, col, Cell], ...]`.

#### renderDiff()

```php
public function renderDiff(int $baseRow = 0, int $baseCol = 0): string
```

Render only the cells that changed since the last `swapBuffers()` call.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$baseRow` | int | Base row offset for positioning |
| `$baseCol` | int | Base column offset for positioning |

#### renderDiffOptimized()

```php
public function renderDiffOptimized(int $baseRow = 0, int $baseCol = 0): string
```

Render differential update with cursor and style optimization.

Uses `CursorOptimizer` and `StyleTracker` to minimize output bytes.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$baseRow` | int | Base row offset for positioning |
| `$baseCol` | int | Base column offset for positioning |

---

## AnsiParser Class

State machine parser for ANSI escape sequences.

```php
use SoloTerm\Screen\AnsiParser;
```

### Static Methods

#### parse()

```php
public static function parse(string $input): array
```

Parse input into AnsiMatch objects (legacy method).

**Returns:** Array of AnsiMatch objects

#### parseFast()

```php
public static function parseFast(string $input): array
```

Parse input into lightweight ParsedAnsi objects (recommended).

**Returns:** Array of ParsedAnsi objects

**Example:**

```php
$tokens = AnsiParser::parseFast("Hello \e[31mWorld\e[0m");
// [
//   ParsedAnsi { raw: "Hello ", command: null },
//   ParsedAnsi { raw: "\e[31m", command: "m", params: "31" },
//   ParsedAnsi { raw: "World", command: null },
//   ParsedAnsi { raw: "\e[0m", command: "m", params: "0" },
// ]
```

---

## ParsedAnsi Class

Lightweight representation of a parsed ANSI token.

```php
use SoloTerm\Screen\ParsedAnsi;
```

### Properties

```php
public string $raw;
public ?string $command;
public ?string $params;
```

| Property | Type | Description |
|----------|------|-------------|
| `$raw` | string | Original text or escape sequence |
| `$command` | ?string | Command character (m, H, J, etc.) or null for text |
| `$params` | ?string | Parameters or null |

---

## Buffer Base Class

Abstract base class for buffers.

```php
use SoloTerm\Screen\Buffers\Buffer;
```

### Properties

```php
public array $lines = [];
```

### Methods

#### expand()

```php
public function expand(int $rows): void
```

Ensure buffer has at least the specified number of rows.

#### fill()

```php
public function fill(int $row, int $startCol, int $endCol, mixed $value): void
```

Fill a region with a value.

#### trim()

```php
public function trim(int $fromRow, int $toRow): void
```

Remove rows from the buffer (for memory management).

#### getChangedRows()

```php
public function getChangedRows(int $sinceSeqNo): array
```

Get rows that changed after the specified sequence number.

---

## Proxy Class

Forwards method calls to multiple buffers.

```php
use SoloTerm\Screen\Buffers\Proxy;
```

### Constructor

```php
public function __construct(array $items)
```

### Magic Method

```php
public function __call(string $method, array $parameters): void
```

Calls the method on all items in the proxy.

**Example:**

```php
// Clear operation on both buffers
$screen->buffers->clear(0, 0, 80);
// Equivalent to:
// $screen->printable->clear(0, 0, 80);
// $screen->ansi->clear(0, 0, 80);
```

---

## CursorOptimizer Class

Optimizes cursor movement sequences to minimize output bytes.

```php
use SoloTerm\Screen\Output\CursorOptimizer;
```

### Constructor

```php
public function __construct(int $width, int $height)
```

### Methods

#### reset()

```php
public function reset(): void
```

Reset cursor position to origin (0, 0).

#### getPosition()

```php
public function getPosition(): array
```

Get current tracked cursor position.

**Returns:** `[row, col]` array

#### moveTo()

```php
public function moveTo(int $row, int $col): string
```

Generate the shortest ANSI sequence to move cursor to target position.

Chooses between strategies like `\r`, `\n`, `\e[H`, relative moves, or absolute positioning based on which produces the fewest bytes.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$row` | int | Target row (0-indexed) |
| `$col` | int | Target column (0-indexed) |

**Returns:** Optimized ANSI escape sequence

#### advance()

```php
public function advance(int $width = 1): void
```

Track cursor advancement after writing characters.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$width` | int | Number of columns to advance |

---

## StyleTracker Class

Tracks active styles and generates minimal transition sequences.

```php
use SoloTerm\Screen\Output\StyleTracker;
```

### Methods

#### reset()

```php
public function reset(): void
```

Reset all tracked styling to defaults.

#### hasStyle()

```php
public function hasStyle(): bool
```

Check if any styling is currently active.

#### transitionTo()

```php
public function transitionTo(Cell $cell): string
```

Generate the ANSI sequence needed to transition from the current style to the target cell's style.

Only emits codes for attributes that actually changed, minimizing output.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$cell` | Cell | Target cell with desired styling |

**Returns:** ANSI escape sequence for style transition

#### resetIfNeeded()

```php
public function resetIfNeeded(): string
```

Get reset sequence if styling is active, empty string otherwise.

**Returns:** `\e[0m` if styled, empty string if not

---

## Constants and Bitmasks

### Style Bitmasks

| Style | Bit Value | SGR Code |
|-------|-----------|----------|
| Bold | 1 | 1 |
| Dim | 2 | 2 |
| Italic | 4 | 3 |
| Underline | 8 | 4 |
| Blink | 16 | 5 |
| Rapid Blink | 32 | 6 |
| Reverse | 64 | 7 |
| Hidden | 128 | 8 |
| Strikethrough | 256 | 9 |

### Extended Color Format

```php
// 256-color
$extColor = [5, $colorIndex];  // Index 0-255

// RGB
$extColor = [2, $red, $green, $blue];  // Each 0-255
```

---

## Type Definitions

```php
// Style bitmask
type StyleBits = int;

// Extended color
type ExtendedColor = array{0: int, 1: int, 2?: int, 3?: int};
// [5, index] for 256-color
// [2, r, g, b] for RGB

// Cell storage (in AnsiBuffer)
type CellStyle = int | array{0: int, 1: ?ExtendedColor, 2: ?ExtendedColor};
```

---

## Error Handling

Screen methods generally don't throw exceptions. Invalid operations are handled gracefully:

- Writing beyond boundaries: Content wraps or is clipped
- Invalid ANSI codes: Ignored silently
- Negative positions: Treated as 0

For debugging, you can inspect buffer state directly:

```php
// Check cursor position
echo "Cursor: {$screen->cursorRow}, {$screen->cursorCol}\n";

// Check buffer dimensions
echo "Lines in buffer: " . count($screen->printable->lines) . "\n";
```
