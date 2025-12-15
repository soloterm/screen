# Cross-Terminal Visual Testing: Ensuring Your TUI Looks Right Everywhere

Building terminal UI applications presents a unique testing challenge: how do you verify that your output *looks* correct? Unit tests can assert that you're generating the right escape sequences, but they can't tell you if those sequences render properly across different terminal emulators.

This post documents the visual testing strategy we built for the Screen library, which ensures consistent rendering across iTerm2 and Ghostty.

## The Problem

Terminal emulators interpret ANSI escape sequences with subtle differences. A sequence that renders perfectly in iTerm2 might break in Ghostty, Alacritty, or the default macOS Terminal. We discovered this firsthand when a "pending wrap state" difference caused content to appear 80 columns offset in Ghostty while working fine in iTerm2.

We needed a testing strategy that would:

1. **Catch visual regressions** when code changes
2. **Verify rendering across multiple terminals** before release
3. **Run automatically in CI** to prevent broken code from merging
4. **Be practical for developers** to generate and update fixtures

## The Solution: Per-Terminal Fixture Testing

Our approach stores expected output fixtures separately for each supported terminal. Tests compare actual output against these fixtures, and CI validates that fixtures exist for all terminals and produce identical results.

### Directory Structure

```
tests/Fixtures/
├── iterm/
│   └── Unit/
│       └── ScreenTest/
│           ├── test_basic_output_1.json
│           └── test_colors_1.json
├── ghostty/
│   └── Unit/
│       └── ScreenTest/
│           ├── test_basic_output_1.json
│           └── test_colors_1.json
└── Renders/
    ├── iterm/
    │   └── Unit/
    │       └── VtailTest/
    │           └── visual_positioning_in_popup.json
    └── ghostty/
        └── Unit/
            └── VtailTest/
                └── visual_positioning_in_popup.json
```

Each terminal has its own fixture directory. When tests run, they automatically detect which terminal they're running in and use the appropriate fixtures.

## Architecture

The visual testing system is built from several focused components:

```
ComparesVisually (trait)           - Test API facade
    ├── VisualTestConfig           - Environment detection, dimensions, modes
    ├── TerminalEnvironment        - Terminal control, resize, output buffering
    ├── ScreenshotSession          - Capture and compare screenshots
    │       └── capture-window     - Swift binary using CGWindowListCreateImage
    ├── VisualFixtureStore         - Fixture I/O, checksums, paths
    └── InteractiveFixturePrompter - User prompts for fixture creation
```

### Terminal Detection

The `VisualTestConfig` class detects the current terminal by checking the `TERM_PROGRAM` environment variable:

```php
private static function detectTerminal(): ?string
{
    $termProgram = getenv('TERM_PROGRAM');

    if ($termProgram === 'iTerm.app') {
        return 'iterm';
    }

    if ($termProgram === 'ghostty') {
        return 'ghostty';
    }

    return null;
}
```

There's no override mechanism—tests must run in the actual terminal. This ensures fixtures are genuinely generated and validated in each environment, not faked.

## Two Types of Visual Tests

### 1. Screenshot Comparison Tests

These tests render content to the real terminal, take a screenshot, then compare it against a reference screenshot. They use the `assertTerminalMatch()` method:

```php
#[Test]
public function colors_render_correctly()
{
    $this->assertTerminalMatch("\e[31mRed\e[0m \e[32mGreen\e[0m \e[34mBlue\e[0m");
}
```

The test:
1. Renders the content to the terminal
2. Captures a screenshot using `CGWindowListCreateImage` (via our Swift helper)
3. Renders the same content through our Screen emulator
4. Captures another screenshot
5. Compares the screenshots pixel-by-pixel using ImageMagick

If there's no fixture yet, it prompts the developer to visually confirm the output looks correct before saving.

### 2. Rendered Output Tests

These tests verify that generated ANSI output produces the expected visual result. They use `appearsToRenderCorrectly()`:

```php
#[Test]
public function visual_positioning_in_popup()
{
    $screen = new Screen(60, 5);
    $screen->write("Line 1\nLine 2\nLine 3");
    
    // Build a popup frame around the screen output
    $rendered = "\e[H\e[2J";
    $rendered .= "\e[3;8H┌─ Popup ─────┐";
    // ... more popup chrome ...
    $rendered .= $screen->output();
    // ... closing border ...

    $this->appearsToRenderCorrectly($rendered);
}
```

When no fixture exists, the test:
1. Clears the terminal and renders the output
2. Prompts the developer: "Does the output look correct? [Y/n]"
3. If confirmed, saves the raw output string as a fixture

On subsequent runs, it compares the output string against the saved fixture.

## Screenshot Capture

Screenshot capture uses a custom Swift tool that leverages macOS's `CGWindowListCreateImage` API:

```swift
// From tests/Support/bin/capture-window.swift
func captureWindow(windowId: CGWindowID, outputPath: String, cropTop: Int = 0) throws {
    guard let image = CGWindowListCreateImage(
        .null,
        .optionIncludingWindow,
        windowId,
        [.boundsIgnoreFraming, .nominalResolution]
    ) else {
        throw CaptureError(message: "Failed to capture window")
    }
    // ... crop title bar and save as PNG
}
```

This approach is more robust than the `screencapture` CLI because:

- **No window activation required** — captures the window directly by ID
- **No settle delays** — the API captures immediately
- **No intermediate files** — crops in memory before saving
- **Single operation** — finds terminal window and captures in one call

The Swift tool is compiled on first use and cached as a binary for fast execution.

## The Test Runner

The `bin/test` script handles the complexity of running visual tests:

```bash
# Run tests without visual testing (CI-safe, fast)
composer test

# Run all tests with screenshot generation
composer test:screenshots

# Generate only missing fixtures
composer test:missing

# Pass filters to PHPUnit
composer test:screenshots -- --filter=positioning
```

### Terminal-Specific Behavior

**For iTerm2**, the test runner automatically resizes the terminal to the required dimensions (180x32) via AppleScript:

```php
private function resizeIterm(): bool
{
    $script = sprintf(
        'tell application "iTerm2"
            tell current session of current window
                set columns to %d
                set rows to %d
            end tell
        end tell',
        $this->config->requiredColumns,
        $this->config->requiredLines
    );

    exec('osascript -e ' . escapeshellarg($script));
    return true;
}
```

**For Ghostty**, which doesn't support direct row/column control, the runner resizes via window bounds and prompts if dimensions don't match.

## CI Validation

The GitHub Actions workflow runs on every push and PR:

```yaml
- name: Execute tests
  env:
    LINES: 32
    COLUMNS: 180
  run: |
    vendor/bin/phpunit 2>&1 | tee phpunit-output.txt
    # Fail if fixtures are missing on main branch
    if [ "${{ github.ref }}" = "refs/heads/main" ]; then
      if grep -q "Fixture with correct content does not exist" phpunit-output.txt; then
        echo "::error::Missing fixtures on main branch"
        exit 1
      fi
    fi
```

More importantly, CI validates that **both terminals have fixtures and they match**:

```yaml
- name: Validate terminal fixtures match
  run: |
    # Find all iterm fixtures and verify ghostty equivalents exist and match
    find tests/Fixtures/iterm -name "*.json" -type f | while read iterm_file; do
      ghostty_file="${iterm_file/iterm/ghostty}"
      
      if [ ! -f "$ghostty_file" ]; then
        echo "::error::Missing Ghostty fixture for: $iterm_file"
        exit 1
      fi
      
      if ! diff -q "$iterm_file" "$ghostty_file" > /dev/null; then
        echo "::error::Fixture mismatch between terminals: $iterm_file"
        exit 1
      fi
    done
```

This ensures:

1. **Every fixture exists for both terminals** — you can't merge code that was only tested in one terminal
2. **Fixtures are identical** — if they differ, it indicates a rendering difference that needs investigation

## The Developer Workflow

Here's how a developer adds a new visual test:

### Step 1: Write the Test

```php
#[Test]
public function my_new_visual_feature()
{
    $screen = new Screen(80, 10);
    $screen->write("Some content...");
    
    $this->appearsToRenderCorrectly($screen->output());
}
```

### Step 2: Generate Fixture in iTerm2

```bash
# Open iTerm2
composer test:screenshots -- --filter=my_new_visual_feature
```

The test runs, displays the output, and asks for confirmation. If it looks right, press `y` to save the fixture.

### Step 3: Generate Fixture in Ghostty

```bash
# Open Ghostty
composer test:screenshots -- --filter=my_new_visual_feature
```

Same process—the test prompts for confirmation and saves the Ghostty fixture.

### Step 4: Verify Fixtures Match

```bash
diff tests/Fixtures/Renders/iterm/Unit/MyTest/my_new_visual_feature.json \
     tests/Fixtures/Renders/ghostty/Unit/MyTest/my_new_visual_feature.json
```

If they differ, investigate why! This usually indicates a terminal compatibility issue that needs fixing.

### Step 5: Commit Both Fixtures

```bash
git add tests/Fixtures/
git commit -m "Add visual test for new feature"
```

CI will verify both fixtures exist and match.

## Handling Fixture Mismatches

When iTerm2 and Ghostty fixtures don't match, it usually means one of:

1. **A real rendering bug** — Your code produces different output in different terminals. This is what we're trying to catch! Fix the code.

2. **Terminal-specific escape sequence support** — Some sequences aren't supported identically. You may need to use a more portable approach.

3. **Test timing issues** — If tests involve animations or timing, results may vary. Make tests deterministic.

The goal is that **identical code produces identical output** in all supported terminals. If it doesn't, that's a bug.

## Benefits of This Approach

1. **Catches real bugs** — We caught the pending wrap issue because fixtures differed between terminals.

2. **Prevents regressions** — Changes that break rendering fail the fixture comparison immediately.

3. **Documents expected behavior** — Fixtures serve as executable documentation of what output should look like.

4. **Enforces cross-terminal testing** — CI fails if you only test in one terminal.

5. **Practical for developers** — The interactive fixture generation makes it easy to add new visual tests.

## Trade-offs

1. **Requires macOS** — Screenshot testing uses `CGWindowListCreateImage`, a macOS-specific API.

2. **Manual fixture generation** — Developers must run tests in each terminal and confirm output visually.

3. **Fixture churn** — Any output change requires regenerating fixtures in both terminals.

4. **Binary fixtures** — Screenshot fixtures are images, which don't diff well in code review.

For a TUI library where visual correctness is critical, these trade-offs are worth it.

## Conclusion

Visual testing for terminal applications requires thinking beyond traditional unit tests. By combining per-terminal fixtures, interactive fixture generation, and CI validation that fixtures exist and match across terminals, we can catch rendering bugs before they reach users.

The key insight is that **if your output differs between terminals, that's probably a bug**. Our testing strategy surfaces these differences early, forcing us to find portable solutions that work everywhere.

Your terminal application's users are running iTerm2, Ghostty, Alacritty, Kitty, WezTerm, and dozens of other emulators. Testing in just one of them isn't enough.
