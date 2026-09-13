<?php

declare(strict_types=1);

namespace App\Support\Scanning;

/**
 * What the ERP prints in a QR and how it reads one back.
 *
 * Codes are short and typed — LOT:RM250914-001, MO:MO-2609-0012,
 * LOC:WH-RM/A-01-03, ITEM:RM-0042 — and are also carried in the floor
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

        if (preg_match('/^(LOT|MO|LOC|ITEM):(.+)$/i', $raw, $m) === 1) {
            return ['type' => strtoupper($m[1]), 'value' => trim($m[2])];
        }

        return ['type' => null, 'value' => $raw];
    }
}
