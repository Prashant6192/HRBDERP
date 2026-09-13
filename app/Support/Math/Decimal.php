<?php

declare(strict_types=1);

namespace App\Support\Math;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Small helpers for showing NUMERIC values without trailing noise.
 */
final class Decimal
{
    /**
     * "42.000000" becomes "42"; "12.500000" becomes "12.5"; "0.000" becomes "0".
     */
    public static function strip(BigDecimal|string|int $value, ?int $scale = null): string
    {
        $decimal = BigDecimal::of($value);

        if ($scale !== null) {
            $decimal = $decimal->toScale($scale, RoundingMode::HalfUp);
        }

        $text = (string) $decimal;

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '' || $text === '-' || $text === '-0' ? '0' : $text;
    }

    public static function stripped(BigDecimal|string|int $value, ?int $scale = null): BigDecimal
    {
        return BigDecimal::of(self::strip($value, $scale));
    }
}
