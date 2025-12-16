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
ENABLE_SCREENSHOT_TESTING=1 composer test

# Generate only missing fixtures
ENABLE_SCREENSHOT_TESTING=2 composer test
```

The test system automatically detects which terminal you're using and stores fixtures in terminal-specific directories (`tests/Fixtures/iterm/` or `tests/Fixtures/ghostty/`).

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
