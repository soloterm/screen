<?php

namespace SoloTerm\Screen\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Screen\Buffers\PrintableBuffer;

class PrintableBufferTest extends TestCase
{
    #[Test]
    public function ascii_write_returns_remainder_when_text_overflows(): void
    {
        $buffer = (new PrintableBuffer)->setWidth(5);

        [$advance, $remainder] = $buffer->writeString(0, 0, 'abcdef');

        $this->assertSame(5, $advance);
        $this->assertSame('f', $remainder);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $buffer->buffer[0]);
    }

    #[Test]
    public function ascii_tab_write_marks_tab_span_with_continuations(): void
    {
        $buffer = (new PrintableBuffer)->setWidth(10);

        [$advance, $remainder] = $buffer->writeString(0, 0, "a\tb");

        $this->assertSame(9, $advance);
        $this->assertSame('', $remainder);
        $this->assertSame('a', $buffer->buffer[0][0]);
        $this->assertSame("\t", $buffer->buffer[0][1]);

        for ($col = 2; $col <= 7; $col++) {
            $this->assertNull($buffer->buffer[0][$col]);
        }

        $this->assertSame('b', $buffer->buffer[0][8]);
    }

    #[Test]
    public function ascii_write_over_continuation_clears_the_original_wide_character(): void
    {
        $buffer = (new PrintableBuffer)->setWidth(6);

        $buffer->writeString(0, 0, '文');
        $buffer->writeString(0, 1, 'a');

        $this->assertSame(' ', $buffer->buffer[0][0]);
        $this->assertSame('a', $buffer->buffer[0][1]);
    }

    #[Test]
    public function writing_beyond_existing_prefix_fills_missing_columns_with_spaces(): void
    {
        $buffer = (new PrintableBuffer)->setWidth(6);

        [$advance, $remainder] = $buffer->writeString(0, 4, 'a');

        $this->assertSame(1, $advance);
        $this->assertSame('', $remainder);
        $this->assertSame([' ', ' ', ' ', ' ', 'a'], $buffer->buffer[0]);
    }

    #[Test]
    public function write_string_tracks_the_touched_dirty_span(): void
    {
        $buffer = (new PrintableBuffer)->setWidth(6);
        $seqNo = 0;
        $buffer->setSeqNoProvider(static function () use (&$seqNo): int {
            return ++$seqNo;
        });

        $buffer->writeString(0, 4, 'a');

        $this->assertSame([0, 4], $buffer->getChangedSpan(0, 0));
    }

    #[Test]
    public function overwriting_a_continuation_expands_the_dirty_span_back_to_the_lead_cell(): void
    {
        $buffer = (new PrintableBuffer)->setWidth(6);
        $seqNo = 0;
        $buffer->setSeqNoProvider(static function () use (&$seqNo): int {
            return ++$seqNo;
        });

        $buffer->writeString(0, 0, '文');
        $firstSeqNo = $buffer->getMaxSeqNo();

        $buffer->writeString(0, 1, 'a');

        $this->assertSame([0, 1], $buffer->getChangedSpan(0, $firstSeqNo));
    }
}
