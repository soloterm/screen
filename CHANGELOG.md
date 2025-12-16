# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Cross-terminal testing** - Visual testing now supports multiple terminals (iTerm and Ghostty) with terminal-specific fixture directories
- **Fixture checking script** - New `bin/check-fixtures` script to verify all visual test fixtures are present

### Changed

- **Rendering uses relative cursor positioning** - `Screen::output()` now uses DECSC/DECRC (save/restore cursor) with CUD (cursor down) instead of newlines between lines, avoiding "pending wrap" terminal inconsistencies and enabling correct rendering at any offset in a parent TUI

### Fixed

- **Pending wrap terminal inconsistencies** - Different terminals handle full-width lines differently when using `\n`; new relative positioning approach eliminates this issue

## [1.1.1] - 2025-12-14

### Added

- **Test runner script** - New `bin/test` script with `--screenshots` and `--missing` flags for easier fixture generation
- **Automatic iTerm resizing** - Test runner automatically resizes iTerm to required dimensions (180x32) when generating fixtures
- **PHP 8.5 support** - Added PHP 8.5 to the test matrix
- **Enhanced README** - Added architecture diagram, differential rendering documentation, and comprehensive ANSI code reference

### Changed

- Test fixtures now require consistent dimensions (180x32) to match CI environment
- CI now fails on main branch if any test fixtures are missing

### Removed

- Removed benchmark tests and their output from the test suite (cleaner test output)

## [1.1.0] - 2025-11-27

### Added

- **Differential rendering support** - `Screen::output($sinceSeqNo)` now accepts an optional sequence number parameter to render only changed lines, providing significant performance improvements for frequently updating displays
- **Sequence number tracking** - New `getSeqNo()` and `getLastRenderedSeqNo()` methods for tracking buffer modifications
- **Cell-based buffer architecture** - New `Cell` class and `CellBuffer` for unified character + style storage with O(1) cell access
- **`CellBuffer` features**:
  - Flat array indexing for efficient memory access
  - Dirty cell tracking for optimized differential rendering
  - Row hash caching for change detection
  - `renderRow()`, `renderDiff()`, and `renderDiffOptimized()` methods
- **Cursor optimizer** - New `CursorOptimizer` class minimizes cursor movement escape sequences
- **Style tracker** - New `StyleTracker` class for efficient style transition calculations
- **State machine ANSI parser** - New `AnsiParser` class with `parseFast()` method, ~2.5x faster than regex-based parsing
- **`Screen::toCellBuffer()`** - Convert dual-buffer content to unified CellBuffer for value-based comparisons

### Fixed

- `CellBuffer::scrollUp()` now preserves buffer height when scrolling more lines than buffer contains
- `CellBuffer::clear()` now clamps negative/out-of-range row/column values to prevent invalid array indices
- `CellBuffer::getRowHash()` now handles variable-length extended color arrays (256-color mode uses 2 elements, RGB uses 4)
- `CellBuffer::setCell()`, `writeChar()`, `writeContinuation()`, `clear()`, `clearLine()`, and `fill()` now properly invalidate row hash cache
- `CellBuffer::insertLines()` and `deleteLines()` now properly shift `lineSeqNos` entries to maintain correct row tracking
- `Screen::newlineWithScroll()` now marks all visible rows dirty when viewport scrolls, ensuring differential renderer includes shifted content

## [1.0.0] - 2024-XX-XX

### Added

- Initial release
- Pure PHP terminal renderer for ANSI escape sequences
- Dual-buffer architecture (PrintableBuffer + AnsiBuffer)
- Support for cursor movement, colors, styles, and screen clearing
- Unicode and wide character support via Grapheme library
- Visual testing system with screenshot comparison
