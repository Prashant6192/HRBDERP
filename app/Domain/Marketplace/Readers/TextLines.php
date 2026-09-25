<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Readers;

/**
 * Small helpers for reading a page's text line by line.
 */
final class TextLines
{
    /**
     * @return list<string>
     */
    public static function of(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return array_values(array_filter(
            array_map(fn (string $l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l) ?? ''), $lines),
            fn (string $l) => $l !== '',
        ));
    }

    /**
     * @param  list<string>  $lines
     * @param  callable(string): bool  $test
     */
    public static function indexOf(array $lines, callable $test): ?int
    {
        foreach ($lines as $i => $line) {
            if ($test($line)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The value printed after a caption: on the same line ("Invoice No:
     * X"), or on the line below when the caption stands alone.
     *
     * @param  list<string>  $lines
     */
    public static function after(array $lines, string $caption): ?string
    {
        foreach ($lines as $i => $line) {
            if (! str_starts_with($line, $caption)) {
                continue;
            }

            $rest = trim(substr($line, strlen($caption)), " :\t-");

            if ($rest !== '') {
                return $rest;
            }

            return $lines[$i + 1] ?? null;
        }

        return null;
    }
}
