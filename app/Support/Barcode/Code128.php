<?php

declare(strict_types=1);

namespace App\Support\Barcode;

use InvalidArgumentException;

/**
 * Code 128 barcodes, drawn by the ERP itself: the bars as widths in
 * modules, and as an SVG a PDF can place at any size. An even run of
 * digits (a UPC such as 21650766) is packed two to a symbol (code set C);
 * anything else printable is code set B.
 */
final class Code128
{
    /** Bar and space widths of each of the 107 symbols, stop included. */
    private const array PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const int START_B = 104;

    private const int START_C = 105;

    private const int STOP = 106;

    /**
     * The widths, in modules, of every bar and space in turn, starting
     * with a bar, quiet zones excluded.
     *
     * @return list<int>
     */
    public static function modules(string $value): array
    {
        if ($value === '') {
            throw new InvalidArgumentException('A barcode needs something to encode.');
        }

        if (preg_match('/^(?:\d\d)+$/', $value) === 1) {
            $codes = [self::START_C, ...array_map('intval', str_split($value, 2))];
        } elseif (preg_match('/^[\x20-\x7E]+$/', $value) === 1) {
            $codes = [self::START_B, ...array_map(fn (string $c) => ord($c) - 32, str_split($value))];
        } else {
            throw new InvalidArgumentException("\"{$value}\" has characters a Code 128 barcode cannot carry.");
        }

        $sum = $codes[0];

        foreach (array_slice($codes, 1) as $i => $code) {
            $sum += $code * ($i + 1);
        }

        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        $widths = [];

        foreach ($codes as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $w) {
                $widths[] = (int) $w;
            }
        }

        return $widths;
    }

    /**
     * The barcode as an SVG, one unit per module, quiet zones of ten
     * modules on each side; it stretches to whatever box it is placed in.
     */
    public static function svg(string $value, int $height = 40): string
    {
        $x = 10;
        $rects = '';

        foreach (self::modules($value) as $i => $w) {
            if ($i % 2 === 0) {
                $rects .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $x, $w, $height);
            }

            $x += $w;
        }

        $total = $x + 10;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" preserveAspectRatio="none" shape-rendering="crispEdges"><rect width="%d" height="%d" fill="#fff"/><g fill="#000">%s</g></svg>',
            $total, $height, $total, $height, $total, $height, $rects,
        );
    }

    /**
     * The bars laid across a given width, quiet zones included, each as its
     * left edge and width in the same unit.
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function bars(string $value, float $width): array
    {
        $modules = self::modules($value);
        $unit = $width / (array_sum($modules) + 20);
        $x = 10 * $unit;
        $bars = [];

        foreach ($modules as $i => $w) {
            if ($i % 2 === 0) {
                $bars[] = [round($x, 3), round($w * $unit, 3)];
            }

            $x += $w * $unit;
        }

        return $bars;
    }

    public static function dataUri(string $value, int $height = 40): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($value, $height));
    }
}
