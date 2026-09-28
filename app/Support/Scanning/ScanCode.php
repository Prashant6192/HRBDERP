<?php

declare(strict_types=1);

namespace App\Support\Scanning;

/**
 * What the ERP prints in a QR and how it reads one back.
 *
 * Codes are short and typed — LOT:RM250914-001, MO:MO-2609-0012,
 * LOC:WH-RM/A-01-03, ITEM:RM-0042, CTN:FG260927-041/3/42 (a carton: its
 * batch, box number and units inside) — and are also carried in the floor
 * URL (/floor/scan?c=…) so a phone's own camera opens the right screen.
 * A bare batch or order number scans too: the resolver tries the types
 * in turn.
 */
final class ScanCode
{
    public const LOT = 'LOT';

    public const ORDER = 'MO';

    public const LOCATION = 'LOC';

    public const ITEM = 'ITEM';

    public const CARTON = 'CTN';

    public static function lot(string $batchNumber): string
    {
        return self::LOT.':'.$batchNumber;
    }

    public static function order(string $number): string
    {
        return self::ORDER.':'.$number;
    }

    public static function location(string $warehouseCode, string $locationCode): string
    {
        return self::LOCATION.':'.$warehouseCode.'/'.$locationCode;
    }

    public static function item(string $code): string
    {
        return self::ITEM.':'.$code;
    }

    /**
     * One carton of a batch: the box number and how many units it holds.
     */
    public static function carton(string $batchNumber, int $box, int|string $units): string
    {
        return self::CARTON.':'.$batchNumber.'/'.$box.'/'.$units;
    }

    /**
     * A carton code's parts. Batch numbers may themselves contain a slash,
     * so the box and units are read from the right.
     *
     * @return array{batch: string, box: int, units: string}|null
     */
    public static function parseCarton(string $value): ?array
    {
        if (preg_match('#^(.+)/(\d{1,5})/(\d+(?:\.\d+)?)$#', trim($value), $m) !== 1) {
            return null;
        }

        return ['batch' => trim($m[1]), 'box' => (int) $m[2], 'units' => $m[3]];
    }

    /**
     * The URL a phone camera opens for a code.
     */
    public static function url(string $code): string
    {
        return route('floor.scan', ['c' => $code]);
    }

    /**
     * @return array{type: string|null, value: string}
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);

        // A printed URL: take the code out of it.
        if (preg_match('/[?&]c=([^&#]+)/', $raw, $m) === 1) {
            $raw = urldecode($m[1]);
        }

        if (preg_match('/^(LOT|MO|LOC|ITEM|CTN):(.+)$/i', $raw, $m) === 1) {
            return ['type' => strtoupper($m[1]), 'value' => trim($m[2])];
        }

        return ['type' => null, 'value' => $raw];
    }
}
