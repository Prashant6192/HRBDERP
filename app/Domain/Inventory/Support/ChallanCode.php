<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Models\StockTransfer;

/**
 * The inward code printed under the QR on a transfer challan: eight
 * characters from an alphabet with no 0/O or 1/I, so it can be read off
 * paper and typed when a scanner is not to hand.
 */
final class ChallanCode
{
    public const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const int LENGTH = 8;

    public static function generate(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (StockTransfer::withTrashed()->where('challan_code', $code)->exists());

        return $code;
    }

    /**
     * As printed: ABCD-2345.
     */
    public static function format(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /**
     * What a scanner or a person typed, reduced to the transfer number and
     * the code it carries. A QR holds the transfer's own address with the
     * code on the end; a person may type "ABCD-2345" or the number and the
     * code together.
     *
     * @return array{number: string|null, id: int|null, code: string|null}
     */
    public static function parse(?string $raw): array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return ['number' => null, 'id' => null, 'code' => null];
        }

        $number = preg_match('/TRF-\d{4}-\d{5}/i', $raw, $n) === 1 ? strtoupper($n[0]) : null;
        $id = preg_match('#/transfers/(\d+)(?:[/?\#]|$)#', $raw, $i) === 1 ? (int) $i[1] : null;
        $code = null;

        if (preg_match('/[?&](?:scan|code)=([A-Za-z0-9\-]+)/', $raw, $m) === 1) {
            $code = self::clean($m[1]);
        } else {
            $rest = preg_replace('/TRF-\d{4}-\d{5}/i', '', $raw) ?? $raw;
            $clean = self::clean($rest);
            $code = strlen($clean) === self::LENGTH ? $clean : null;
        }

        return ['number' => $number, 'id' => $id, 'code' => $code];
    }

    private static function clean(string $text): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $text) ?? '');
    }
}
