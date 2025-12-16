---
title: Bitmasks Deep Dive
description: How Screen uses bitmasks for efficient ANSI style tracking.
---

# The Elegant Power of Bitmasks for ANSI Style Tracking

When building a terminal renderer, you need to track a lot of styling information for every single cell: bold, italic, underline, blink, foreground color, background color... the list goes on. In SoloTerm's Screen library, we chose bitmasks for this job, and it turned out to be one of our best architectural decisions.

## The Problem

Every cell in a terminal can have multiple styles applied simultaneously. Text can be **bold** *and* underlined *and* red *and* have a blue background. A naive approach might use an array or object:

```php
$cell = [
    'bold' => true,
    'italic' => false,
    'underline' => true,
    'foreground' => 31,  // red
    'background' => 44,  // blue
];
```

This works, but it's wasteful. In a 200×50 terminal, you have 10,000 cells. Each cell might have 10+ properties. That's a lot of memory overhead from PHP arrays, and comparing two cells requires checking every property.

## The Bitmask Solution

Instead, we assign each ANSI code a unique bit position:

```php
$this->codes = array_reduce($supported, function ($carry, $code) {
    // Every code gets a unique bit value, via left shift.
    $carry[$code] = 1 << count($carry);
    return $carry;
}, []);
```

This creates a mapping like:
- Code 0 (reset): bit 0 → value 1
- Code 1 (bold): bit 1 → value 2
- Code 2 (dim): bit 2 → value 4
- Code 3 (italic): bit 3 → value 8
- ...and so on

Now a cell's entire style state is just **one integer**. Bold + underline + red foreground? That's `2 | 16 | 4096` = `4114`. A single 64-bit integer can track over 60 different style states.

## Why This Is Awesome

### 1. Blazing Fast Operations

Adding a style is a single OR operation:

```php
$this->active |= $this->codes[$code];
```

Removing a style is a single AND-NOT:

```php
$this->active &= ~$this->codes[$bitToUnset];
```

Checking if a style is active:

```php
if (($bits & $bit) === $bit) {
    // style is active
}
```

These are single CPU instructions. No loops, no hash lookups, no string comparisons.

### 2. Minimal Memory Footprint

Instead of storing an array with multiple keys per cell, we store a single integer. For 10,000 cells, that's the difference between megabytes of array overhead and about 80KB of integers.

### 3. Instant Equality Checks

Comparing two cells to see if their styles match? One integer comparison:

```php
if ($cell1 === $cell2) // done
```

This is critical for differential rendering, where we need to quickly detect which cells have changed between frames.

### 4. Efficient Diff Computation

Finding what changed between two style states is elegant:

```php
// What styles were added?
$uniqueBits = $newCell & ~$previousCell;

// What styles were removed?
$turnedOffBits = $previousCell & ~$newCell;
```

We use this in `compressedAnsiBuffer()` to emit only the ANSI codes that actually changed, minimizing the bytes sent to the terminal.

### 5. Early Termination

Because bits are assigned in ascending powers of 2, we can break out of loops early:

```php
foreach ($this->codes as $code => $bit) {
    if ($bit > $bits) {
        break;  // No more bits can possibly be set
    }
    // ...
}
```

## Handling Colors Intelligently

Standard 8/16 colors fit nicely in the bitmask (codes 30-37, 40-47, 90-97, 100-107). But 256-color and RGB modes need more data than a single bit. We handle this with a hybrid approach:

```php
protected function cellValue(): int|array
{
    if (is_null($this->extendedForeground) && is_null($this->extendedBackground)) {
        return $this->active;  // Just the integer
    }

    // Extended colors need more storage
    return [
        $this->active,
        $this->extendedForeground,
        $this->extendedBackground
    ];
}
```

Most cells use basic colors and stay as a single integer. Only cells with 256/RGB colors pay the array overhead—a classic "pay for what you use" design.

## Mutually Exclusive States

One wrinkle: some ANSI codes are mutually exclusive. You can't have both red (31) and blue (34) foreground simultaneously—the later one wins. We handle this by clearing the old bits before setting new ones:

```php
if ($this->codeInRange($code, $this->foreground)) {
    $this->resetForeground();  // Clear all foreground bits
}

$this->active |= $this->codes[$code];  // Set the new one
```

Same for decorations and their resets. Bold (1) and "not bold" (22) can't coexist.

## The Bottom Line

Bitmasks are one of those techniques that feel like "premature optimization" until you actually need the performance. For a terminal renderer processing thousands of cells at 40+ FPS, they're not premature at all—they're essential.

The real elegance is how naturally they map to the problem domain. ANSI styles *are* a set of on/off flags. Bitmasks *are* a set of on/off flags. The data structure matches the semantics perfectly, and we get incredible performance as a bonus.

Sometimes the old techniques really are the best ones.
