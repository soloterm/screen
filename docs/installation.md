---
title: Installation
description: How to install and configure the Screen package.
---

# Installation

## Requirements

Before installing Screen, ensure your environment meets these requirements:

| Requirement | Version | Notes |
|-------------|---------|-------|
| PHP | 8.1+ | Required for modern type features |
| mbstring | * | For Unicode string handling |
| Architecture | 64-bit | Required for bitmask operations |

Screen is a pure PHP package with no system dependencies. It works on any operating system where PHP runs.

## Install via Composer

```bash
composer require soloterm/screen
```

This will also install the `soloterm/grapheme` dependency, which handles Unicode character width calculation.

## Verify Installation

Create a simple test script to verify everything works:

```php
<?php

require 'vendor/autoload.php';

use SoloTerm\Screen\Screen;

$screen = new Screen(40, 5);
$screen->write("\e[32mScreen installed successfully!\e[0m\n");
$screen->write("Dimensions: {$screen->width}x{$screen->height}");

echo $screen->output();
```

Run it:

```bash
php test.php
```

You should see green text displaying "Screen installed successfully!" followed by the dimensions.

## Dependencies

Screen has one dependency:

### soloterm/grapheme

The Grapheme package provides accurate Unicode character width calculation. This is essential for terminal rendering because:

- ASCII characters are 1 cell wide
- CJK characters are 2 cells wide
- Emoji are typically 2 cells wide
- Zero-width characters (like combining accents) are 0 cells wide

Without accurate width calculation, cursor positioning breaks and output becomes misaligned.

## Development Installation

If you're contributing to Screen or want to run the test suite:

```bash
# Clone the repository
git clone https://github.com/soloterm/screen.git
cd screen

# Install dependencies
composer install

# Run tests
composer test
```

### Visual Screenshot Testing

Screen includes visual screenshot tests that compare actual terminal output. These require:

- macOS
- iTerm2 or Ghostty terminal
- ImageMagick (for image comparison)

To enable screenshot tests:

```bash
# Generate all fixtures
composer test:screenshots

# Generate only missing or out-of-sync fixtures in both terminals
composer test:missing

# Limit to one terminal window when needed
composer test:missing -- --terminal=iterm

# Validate fixture dimensions + cross-terminal parity
composer test:fixtures

# Re-run only last failures
composer test:failed
```

`composer test:screenshots` and `composer test:missing` run in fresh terminal relay windows to keep dimensions aligned with CI (`180x32`).

- iTerm is resized automatically.
- Ghostty opens in a calibrated window size and prompts if manual adjustment is still needed.
- `composer test:missing` without `--terminal` runs both iTerm and Ghostty relays and requires both apps to be installed.

Fixtures are stored in terminal-specific directories (`tests/Fixtures/iterm/` and `tests/Fixtures/ghostty/`).

### CI Notes

The `Tests` workflow runs on Linux across PHP `8.1` to `8.5` and enforces fixture quality by failing on missing-fixture test output and running `composer test:fixtures`.

Two known multibyte parity edge-case tests are intentionally skipped on non-Darwin runners and validated on macOS fixture generation flows.

## Configuration

Screen requires no configuration. All settings are passed via constructor parameters:

```php
// Create a screen with custom dimensions
$screen = new Screen(
    width: 120,   // columns
    height: 40    // rows
);
```

### Optional: Query Response Handler

If you need to handle terminal queries (like cursor position requests), you can configure a response handler:

```php
$screen->respondToQueriesVia(function (string $query) {
    // Handle terminal query
    // Return response string or null
});
```

This is an advanced feature typically only needed when integrating with actual terminal I/O.

## Next Steps

Now that Screen is installed, learn the basics:

- [Basic Usage](basic-usage) - Write content and render output
- [Architecture](architecture) - Understand how Screen works internally
