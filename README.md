# Solo Screen

Screen is a terminal renderer written in pure PHP. It powers [Solo for Laravel](https://github.com/soloterm/solo) and
can be used to build rich text-based user interfaces in any PHP application.

> [!NOTE]
> Screen is a library intended to be integrated into PHP applications. It is not a standalone terminal application.

## About terminal renderers

A terminal renderer processes text and ANSI escape sequences to create a virtual representation of terminal output.
Unlike a full terminal emulator, Screen focuses specifically on correctly interpreting and rendering text content with
formatting rather than handling input, interactive sessions, or process management.

Terminal renderers interpret escape sequences to:

- Track cursor position
- Apply text colors and styles (bold, underline, etc.)
- Manage screen content
- Handle special character sets
- Generate a final rendered output

Screen implements this functionality in pure PHP, allowing developers to build terminal user interfaces without relying
on external dependencies or native code.

## Why this exists

Screen was originally created to solve a specific problem in [Solo for Laravel](https://github.com/soloterm/solo).

Solo provides a TUI (Text User Interface) that runs multiple processes simultaneously in separate panels, similar to
tmux. However, when these processes output ANSI escape codes for cursor movement and screen manipulation, they could
potentially "break out" of their visual containers and interfere with other parts of the interface.

To solve this problem, Screen creates a virtual terminal buffer where:

1. All ANSI operations (cursor movements, color changes, screen clears) are safely interpreted within an isolated
   environment
2. The final rendered state is captured after all operations are processed
3. Only the final visual output is displayed to the user's terminal

This approach provides complete control over how terminal output is rendered, ensuring that complex ANSI operations stay
contained within their designated areas. While initially built for Solo, Screen has evolved into a standalone library
that can be used in any PHP application requiring terminal rendering.

## Features

- **Pure PHP Implementation**: Only one dependency ([Grapheme](https://github.com/soloterm/grapheme), another Solo
  library)
- **Comprehensive ANSI Support**: Handles cursor positioning, text styling, and screen manipulation
- **Unicode/Multibyte Support**: Properly handles UTF-8 characters including emojis and wide characters
- **Buffer Management**: Maintains separate buffers for text content and styling
- **Character Width Handling**: Correctly calculates display width for CJK and other double-width characters
- **Scrolling**: Support for vertical scrolling with proper content management
- **Relative Positioning**: Output can be rendered at any position in a parent TUI without interference

## Installation

Install via Composer:

```shell
composer require soloterm/screen
```

## Requirements

- PHP 8.1 or higher
- mbstring extension

## Basic usage

Here's a simple example of using Screen:

```php
use SoloTerm\Screen\Screen;

// Create a screen with dimensions (columns, rows)
$screen = new Screen(80, 24);

// Write text and ANSI escape sequences
$screen->write("Hello, \e[1;32mWorld!\e[0m");

// Move cursor and add more text
$screen->write("\e[5;10HPositioned text");

// Get the rendered content
echo $screen->output();
```

## Core concepts

Screen operates with several key components:

### Screen

The main class that coordinates all functionality. It takes care of cursor positioning, content writing, and rendering
the final output.

```php
$screen = new Screen(80, 24); // width, height
$screen->write("Text and ANSI codes");
```

### Buffers

Screen uses multiple buffer types to track content and styling:

- **PrintableBuffer**: Stores visible characters and handles width calculations
- **AnsiBuffer**: Tracks styling information (colors, bold, underline, etc.)

### ANSI processing

Screen correctly interprets ANSI escape sequences for:

- Cursor movement (up, down, left, right, absolute positioning)
- Text styling (colors, bold, italic, underline)
- Screen clearing and line manipulation
- Scrolling

## Architecture

Screen uses a dual-buffer architecture to separate content from styling:

```
┌──────────────────────────────────────────────────────────────────┐
│                            Screen                                │
│                                                                  │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────────────┐   │
│  │ AnsiParser  │───▶│    Proxy    │───▶│       Buffers       │   │
│  │             │    │             │    │                     │   │
│  │ Splits into │    │ Coordinates │    │  ┌───────────────┐  │   │
│  │ text + ANSI │    │ writes to   │    │  │PrintableBuffer│  │   │
│  └─────────────┘    │ both buffers│    │  │ (characters)  │  │   │
│                     └─────────────┘    │  ├───────────────┤  │   │
│                                        │  │  AnsiBuffer   │  │   │
│                                        │  │   (styles)    │  │   │
│                                        │  └───────────────┘  │   │
│                                        └──────────┬──────────┘   │
│                                                   │              │
│                                                   ▼              │
│                                        ┌─────────────────────┐   │
│                                        │      output()       │   │
│                                        │   Combines buffers  │   │
│                                        │   into final ANSI   │   │
│                                        └─────────────────────┘   │
└──────────────────────────────────────────────────────────────────┘
```

- **AnsiParser**: A state machine that splits input into printable text and ANSI escape sequences
- **Proxy**: Coordinates writes to both buffers simultaneously, keeping them in sync
- **PrintableBuffer**: Stores visible characters, handles grapheme clusters and wide character width calculations
- **AnsiBuffer**: Stores styling as efficient bitmasks, with support for 256-color and RGB

This separation allows Screen to efficiently track what changed and optimize rendering.

## Advanced features

### Rendering at arbitrary positions (popup windows, panels)

When building TUIs with multiple Screen instances rendered at different positions (like popup windows or panels), you
need to handle cursor positioning carefully. Screen's `output()` method uses **relative cursor positioning** to avoid
the "pending wrap state" problem that causes rendering issues across different terminals.

#### The pending wrap problem

When a line is filled to exactly the terminal width, terminals enter a "pending wrap" state. The behavior of `\n` and
`\r` in this state varies between terminal emulators:

- **iTerm2**: A newline after a full line moves down one row
- **Ghostty**: May move down two rows or position content incorrectly

This inconsistency can cause content to appear offset by an entire screen width in some terminals.

#### How Screen solves this

Screen's `output()` method uses DEC save/restore cursor (DECSC/DECRC) with cursor down (CUD) sequences instead of
newlines:

```
ESC 7           Save cursor position (origin point)
[line 1 content]
ESC 8           Restore to origin
ESC [1B         Move down 1 row
[line 2 content]
ESC 8           Restore to origin  
ESC [2B         Move down 2 rows
[line 3 content]
...
```

This approach:
- **Avoids pending wrap entirely** — no `\n` characters between lines means wrap state doesn't matter
- **Uses relative positioning** — output renders correctly at any cursor position in a parent TUI
- **Works consistently** — same behavior in iTerm2, Ghostty, and other terminals

#### Rendering a Screen at a specific position

To render a Screen at a specific position in your TUI:

```php
use SoloTerm\Screen\Screen;

// Create a screen for a popup/panel
$popup = new Screen(40, 10);
$popup->write("Popup content here...");

// Position the parent terminal's cursor where you want the popup
echo "\e[5;20H";  // Move to row 5, column 20

// Render the popup - it will use relative positioning from this point
echo $popup->output();
```

The Screen will render its content starting from wherever the cursor is positioned, with each line placed relative to
that origin point.

### Differential rendering

For high-performance applications like TUIs with frequent updates, Screen supports differential rendering that only
outputs changed lines:

```php
$screen = new Screen(80, 24);
$screen->write("Initial content");

// Get the full output and capture the sequence number
$output = $screen->output();
$seqNo = $screen->getLastRenderedSeqNo();

// ... later, after some updates ...
$screen->write("\e[5;1HUpdated line");

// Only get the changed lines (with cursor positioning)
$diff = $screen->output($seqNo);
```

This is particularly useful when building interfaces that update at high frame rates (e.g., 40 FPS) where
re-rendering the entire screen would be wasteful.

### Cursor positioning

```php
// Move cursor to position (row 5, column 10)
$screen->write("\e[5;10H");

// Move cursor up 3 lines
$screen->write("\e[3A");

// Save and restore cursor position
$screen->write("\e7"); // Save
$screen->write("More text");
$screen->write("\e8"); // Restore
```

### Text styling

```php
// Bold red text
$screen->write("\e[1;31mImportant message\e[0m");

// Background colors
$screen->write("\e[44mBlue background\e[0m");

// 256-color support
$screen->write("\e[38;5;208mOrange text\e[0m");

// RGB colors
$screen->write("\e[38;2;255;100;0mCustom color\e[0m");
```

### Screen manipulation

```php
// Clear screen
$screen->write("\e[2J");

// Clear from cursor to end of line
$screen->write("\e[0K");

// Insert lines
$screen->write("\e[2L");

// Scroll up
$screen->write("\e[2S");
```

## Supported ANSI codes

Screen supports a comprehensive set of ANSI escape sequences:

### Cursor movement (CSI sequences)

| Code | Name | Description |
|------|------|-------------|
| `ESC[nA` | CUU | Cursor up n lines |
| `ESC[nB` | CUD | Cursor down n lines |
| `ESC[nC` | CUF | Cursor forward n columns |
| `ESC[nD` | CUB | Cursor backward n columns |
| `ESC[nE` | CNL | Cursor to beginning of line, n lines down |
| `ESC[nF` | CPL | Cursor to beginning of line, n lines up |
| `ESC[nG` | CHA | Cursor to column n |
| `ESC[n;mH` | CUP | Cursor to row n, column m |
| `ESC[nI` | CHT | Cursor forward n tab stops |
| `ESC7` | DECSC | Save cursor position |
| `ESC8` | DECRC | Restore cursor position |

### Erase functions

| Code | Name | Description |
|------|------|-------------|
| `ESC[0J` | ED | Erase from cursor to end of screen |
| `ESC[1J` | ED | Erase from start of screen to cursor |
| `ESC[2J` | ED | Erase entire screen |
| `ESC[0K` | EL | Erase from cursor to end of line |
| `ESC[1K` | EL | Erase from start of line to cursor |
| `ESC[2K` | EL | Erase entire line |

### Scrolling

| Code | Name | Description |
|------|------|-------------|
| `ESC[nS` | SU | Scroll up n lines |
| `ESC[nT` | SD | Scroll down n lines |
| `ESC[nL` | IL | Insert n lines at cursor |

### Text styling (SGR - Select Graphic Rendition)

| Code | Description |
|------|-------------|
| `0` | Reset all attributes |
| `1` | Bold |
| `2` | Dim |
| `3` | Italic |
| `4` | Underline |
| `5` | Blink |
| `7` | Reverse video |
| `8` | Hidden |
| `9` | Strikethrough |
| `22` | Normal intensity (not bold/dim) |
| `23` | Not italic |
| `24` | Not underlined |
| `25` | Not blinking |
| `27` | Not reversed |
| `28` | Not hidden |
| `29` | Not strikethrough |
| `30-37` | Foreground color (standard) |
| `38;5;n` | Foreground color (256-color) |
| `38;2;r;g;b` | Foreground color (RGB) |
| `39` | Default foreground |
| `40-47` | Background color (standard) |
| `48;5;n` | Background color (256-color) |
| `48;2;r;g;b` | Background color (RGB) |
| `49` | Default background |
| `90-97` | Foreground color (bright) |
| `100-107` | Background color (bright) |

## Custom integrations

You can respond to terminal queries by setting a callback:

```php
$screen->respondToQueriesVia(function($response) {
    // Process response (like cursor position)
    echo $response;
});
```

> [!NOTE]
> This is still a work in progress. We need some more tests / use cases here.

## Example: building a simple UI

```php
use SoloTerm\Screen\Screen;

$screen = new Screen(80, 24);

// Draw a border
$screen->write("┌" . str_repeat("─", 78) . "┐\n");
for ($i = 0; $i < 22; $i++) {
    $screen->write("│" . str_repeat(" ", 78) . "│\n");
}
$screen->write("└" . str_repeat("─", 78) . "┘");

// Add a title
$screen->write("\e[1;30H\e[1;36mMy Application\e[0m");

// Add some content
$screen->write("\e[5;5HWelcome to the application!");
$screen->write("\e[7;5HPress 'q' to quit.");

// Render
echo $screen->output();
```

## Handling unicode and wide characters

Screen properly handles Unicode characters including emoji and CJK characters that take up multiple columns:

```php
$screen->write("Regular text: Hello");
$screen->write("\nWide characters: 你好世界");
$screen->write("\nEmoji: 🚀 👨‍👩‍👧‍👦 🌍");
```

## Testing

Screen includes a comprehensive test suite with visual comparison testing that validates output against real terminal
behavior.

```shell
composer test
```

### Available test commands

| Command | Description |
|---------|-------------|
| `composer test` | Run tests without screenshot generation |
| `composer test:screenshots` | Generate all fixtures (requires iTerm or Ghostty) |
| `composer test:missing` | Generate only missing or out-of-sync fixtures |
| `composer test:failures` | Re-run failed tests first, stop on first failure |
| `composer test:fixtures` | Validate fixture integrity across terminals |

You can pass additional PHPUnit options using `--`:

```shell
# Run only emoji tests with screenshots
composer test:screenshots -- --filter="emoji"

# Generate missing fixtures for a specific test class
composer test:missing -- --filter="MultibyteTest"
```

### Visual testing

Screen employs screenshot-based testing that compares rendered output against real terminal behavior. The system
supports both **iTerm2** and **Ghostty** terminals to ensure cross-terminal compatibility.

How it works:

1. The test renders content in a real terminal (iTerm2 or Ghostty)
2. It captures a screenshot using macOS's `CGWindowListCreateImage` API
3. It runs the same content through the Screen renderer
4. It captures another screenshot
5. It compares the screenshots pixel-by-pixel using ImageMagick

This ensures Screen's rendering accurately matches real terminal behavior for:

- Multi-byte characters and emoji
- Complex ANSI formatting
- Cursor movements and positioning
- Scrolling behavior
- Line wrapping
- Terminal-specific edge cases (like pending wrap state)

### Requirements for visual testing

- macOS
- iTerm2 or Ghostty terminal
- ImageMagick (`brew install imagemagick`)

The test runner will automatically resize your terminal window to the required dimensions (180x32) for iTerm. For
Ghostty, you'll be prompted to resize manually.

### Fixture structure

Fixtures are stored per-terminal to account for rendering differences:

```
tests/Fixtures/
├── iterm/           # iTerm2-specific fixtures
│   └── Unit/
│       └── TestClass/
│           └── test_name.json
└── ghostty/         # Ghostty-specific fixtures
    └── Unit/
        └── ...
```

When running tests without screenshot generation, the system uses stored fixtures for comparison, making tests fast and
suitable for CI/CD pipelines. In CI (where no terminal is available), iTerm fixtures are used since we validate that
iTerm and Ghostty fixtures are identical.

## Contributing

Contributions are welcome! Please feel free to submit a pull request.

## License

The MIT License (MIT).

## Support

This is free! If you want to support me:

- Check out my courses:
    - [Database School](https://databaseschool.com)
    - [Screencasting](https://screencasting.com)
- Help spread the word about things I make

## Related Projects

Screen is part of the SoloTerm ecosystem of Laravel and PHP development tools:

- [Solo](https://github.com/soloterm/solo) - All-in-one Laravel command for local development
- [Dumps](https://github.com/soloterm/dumps) - Laravel command to intercept dumps
- [Grapheme](https://github.com/soloterm/grapheme) - Unicode grapheme width calculator
- [Notify](https://github.com/soloterm/notify) - PHP package for desktop notifications via OSC escape sequences
- [Notify Laravel](https://github.com/soloterm/notify-laravel) - Laravel integration for soloterm/notify
- [TNotify](https://github.com/soloterm/tnotify) - Standalone, cross-platform CLI for desktop notifications
- [VTail](https://github.com/soloterm/vtail) - Vendor-aware tail for Laravel logs

## Credits

Solo Screen was developed by Aaron Francis. If you like it, please let me know!

- Twitter: https://twitter.com/aarondfrancis
- Website: https://aaronfrancis.com
- YouTube: https://youtube.com/@aarondfrancis
- GitHub: https://github.com/aarondfrancis
