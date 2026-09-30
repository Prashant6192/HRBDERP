<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\DTOs;

/**
 * What the uploader's browser read on a label file's picture pages: each
 * page's words (OCR) and the barcodes on it. Nothing here is trusted
 * beyond being text: the marketplace's parser decides what it means, and
 * every parcel it makes can still be corrected on the batch screen.
 */
final class BrowserPages
{
    private const int MAX_PAGES = 1000;

    private const int MAX_TEXT = 20000;

    private const int MAX_CODES = 12;

    /**
     * Page number => the page's text, with each barcode on a line of its
     * own as "[barcode code_128] 219100000000011".
     *
     * @return array<int, string>
     */
    public static function fromJson(?string $json): array
    {
        $data = json_decode((string) $json, true);

        if (! is_array($data) || ! is_array($data['pages'] ?? null)) {
            return [];
        }

        $pages = [];

        foreach (array_slice($data['pages'], 0, self::MAX_PAGES) as $page) {
            $number = is_array($page) ? (int) ($page['page'] ?? 0) : 0;

            if ($number < 1 || $number > self::MAX_PAGES) {
                continue;
            }

            $text = is_string($page['text'] ?? null) ? mb_substr($page['text'], 0, self::MAX_TEXT) : '';
            $codes = [];

            foreach (array_slice(is_array($page['codes'] ?? null) ? $page['codes'] : [], 0, self::MAX_CODES) as $code) {
                $format = is_array($code) && is_string($code['format'] ?? null) ? strtolower($code['format']) : '';
                $value = is_array($code) && is_string($code['text'] ?? null) ? trim(str_replace(["\r", "\n"], ' ', $code['text'])) : '';

                if (preg_match('/^[a-z0-9_]{2,20}$/', $format) === 1 && $value !== '') {
                    $codes[] = "[barcode {$format}] ".mb_substr($value, 0, 1000);
                }
            }

            if (trim($text) === '' && $codes === []) {
                continue;
            }

            $pages[$number] = trim($text."\n".implode("\n", $codes));
        }

        return $pages;
    }
}
