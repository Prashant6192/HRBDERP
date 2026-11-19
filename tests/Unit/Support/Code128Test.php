<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Barcode\Code128;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Code 128 as Blinkit's labels print it. The expected widths were checked
 * by scanning the drawn barcodes with a barcode reader.
 */
class Code128Test extends TestCase
{
    #[Test]
    public function an_even_run_of_digits_is_packed_two_to_a_symbol(): void
    {
        // Start C, 21 65 07 66, checksum, stop: 6 symbols of 11 modules and a stop of 13.
        $modules = Code128::modules('21650766');

        $this->assertSame(6 * 6 + 7, count($modules));
        $this->assertSame(6 * 11 + 13, array_sum($modules));
        $this->assertSame([2, 1, 1, 2, 3, 2], array_slice($modules, 0, 6), 'Start C');
        $this->assertSame([2, 3, 3, 1, 1, 1, 2], array_slice($modules, -7), 'Stop');
    }

    #[Test]
    public function other_text_uses_code_set_b_with_its_checksum(): void
    {
        // Start B (104) + "A" (33): checksum (104 + 33) % 103 = 34.
        $modules = Code128::modules('A');

        $this->assertSame([2, 1, 1, 2, 1, 4], array_slice($modules, 0, 6), 'Start B');
        $this->assertSame([1, 1, 1, 3, 2, 3], array_slice($modules, 6, 6), '"A"');
        $this->assertSame([1, 3, 1, 1, 2, 3], array_slice($modules, 12, 6), 'Checksum 34');
    }

    #[Test]
    public function the_bars_fill_the_width_with_quiet_zones_either_side(): void
    {
        $bars = Code128::bars('21650766', 100.0);
        $unit = 100.0 / (6 * 11 + 13 + 20);

        $this->assertEqualsWithDelta(10 * $unit, $bars[0][0], 0.001);
        $last = end($bars);
        $this->assertEqualsWithDelta(100.0 - 10 * $unit, $last[0] + $last[1], 0.01);
        $this->assertStringContainsString('<rect', Code128::svg('21650766'));
    }

    #[Test]
    public function text_a_barcode_cannot_carry_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Code128::modules('२१६५');
    }
}
