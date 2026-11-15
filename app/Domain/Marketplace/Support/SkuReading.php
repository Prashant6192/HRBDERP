<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Support;

/**
 * What a marketplace SKU and its label title say in plain words: the
 * product, its size and whether it is a pack — "RR-LHK-HO-001
 * (300ML-2PacK)" with "Rahat Rooh Lauki Oil Herbal Hair Oil for Strong,
 * Thick & Healthy Hai" reads as "Lauki Oil Herbal Hair Oil · 300 ml ·
 * Pack of 2". It then suggests the ERP product it most likely is, for a
 * person to confirm when mapping the SKU.
 */
final class SkuReading
{
    /** Words every product shares, which tell products apart not at all. */
    private const array COMMON = [
        'rahat', 'rooh', 'ayurvedic', 'ayurveda', 'herbal', 'natural', 'oil', 'for', 'and', 'the', 'with', 'of', 'in',
        'ml', 'l', 'g', 'gm', 'kg', 'pack', 'combo', 'new', 'pure', 'best', 'hair', 'care', 'size',
    ];

    /**
     * @param  string|null  $name  the product in plain words, or null when the label gave none
     * @param  string|null  $size  "300 ml", "1 l", "100 g"
     * @param  int|null  $pack  pieces in one order when it is a pack (2 for a pack of two)
     */
    private function __construct(
        public readonly ?string $name,
        public readonly ?string $size,
        public readonly ?int $pack,
    ) {}

    public static function of(string $sku, ?string $title, ?string $brand = null): self
    {
        $title = trim((string) $title);

        // A store SKU that is only a number says nothing; the label may have
        // run it into the title ("67962894614801 Rahat Rooh …").
        $title = trim((string) preg_replace('/^\d{6,20}\s+/', '', $title));

        $size = self::size($sku) ?? self::size($title);
        $pack = self::pack($sku) ?? self::pack($title);

        return new self(self::name($title, $brand), $size, $pack);
    }

    /**
     * The ERP product this most likely is, from the given products, or
     * null when none stands out. A pack product of the right size wins
     * for a pack ("… (Pack of 2)"); otherwise the single product, taken
     * $pack times.
     *
     * @param  iterable<array{id: int, name: string, pack_of: int|null, pack_quantity: int|null}>  $products
     * @return array{id: int, pieces: int}|null
     */
    public function suggest(iterable $products): ?array
    {
        if ($this->name === null) {
            return null;
        }

        $words = self::words($this->name);

        if ($words === []) {
            return null;
        }

        $scored = [];
        $byId = [];

        foreach ($products as $p) {
            $byId[$p['id']] = $p;

            if ($p['pack_of'] !== null) {
                continue;
            }

            $theirs = self::words($p['name']);
            $shared = count(array_intersect($words, $theirs));

            if ($shared === 0) {
                continue;
            }

            // Every distinctive word of the product's name should be in the
            // title; words the title adds are its marketing.
            $score = $shared * 2 - count(array_diff($theirs, $words));
            $theirSize = self::size($p['name']);

            if ($this->size !== null && $theirSize !== null) {
                $score += $theirSize === $this->size ? 4 : -6;
            }

            $scored[$p['id']] = $score;
        }

        if ($scored === []) {
            return null;
        }

        arsort($scored);
        $ids = array_keys($scored);
        $best = $ids[0];

        // Only a clear winner is suggested.
        if ($scored[$best] < 2 || (isset($ids[1]) && $scored[$ids[1]] === $scored[$best])) {
            return null;
        }

        $pieces = $this->pack ?? 1;

        if ($pieces > 1) {
            foreach ($byId as $p) {
                if ($p['pack_of'] === $best && $p['pack_quantity'] === $pieces) {
                    return ['id' => $p['id'], 'pieces' => 1];
                }
            }
        }

        return ['id' => $best, 'pieces' => $pieces];
    }

    /**
     * "300ML", "200 ml", "1 Ltr", "100gm" → "300 ml", "200 ml", "1 l", "100 g".
     */
    private static function size(string $text): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(ml|ltr|litre|liter|l|gm|g|kg)\b/i', $text, $m) !== 1) {
            return null;
        }

        $unit = match (strtolower($m[2])) {
            'ltr', 'litre', 'liter', 'l' => 'l',
            'gm', 'g' => 'g',
            'kg' => 'kg',
            default => 'ml',
        };

        return ((float) $m[1] == (int) $m[1] ? (string) (int) $m[1] : $m[1]).' '.$unit;
    }

    /**
     * "2PacK", "Pack of 2", "PO2", "(Pack of 3)", "x2" → 2, 2, 2, 3, 2.
     */
    private static function pack(string $text): ?int
    {
        foreach ([
            '/(\d{1,2})\s*-?\s*pa?c?k\b/i',
            '/pack\s*of\s*(\d{1,2})\b/i',
            '/\bpo\s*(\d{1,2})\b/i',
            '/(?:^|[\s(_-])x\s*(\d{1,2})\b/i',
            '/combo\s*of\s*(\d{1,2})\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $text, $m) === 1 && (int) $m[1] >= 2 && (int) $m[1] <= 12) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * The product's own name from a marketplace title: before the
     * marketing ("| Herbal Oil for Stress…", "for Strong, Thick…"), the
     * size and the "Size: …" note, without the brand.
     */
    private static function name(string $title, ?string $brand): ?string
    {
        if ($title === '') {
            return null;
        }

        $name = preg_split('/\s+\|\s+|,\s*Size\s*:|\s+for\s+|\s+-\s+|-\s*\d|\s*\(\s*\d/i', $title)[0] ?? $title;
        $name = (string) preg_replace('/\b\d+(?:\.\d+)?\s*(?:ml|ltr|litre|liter|l|gm|g|kg)\b/i', '', $name);

        if ($brand !== null && $brand !== '') {
            $name = (string) preg_replace('/^\s*'.preg_quote($brand, '/').'\s*/i', '', $name);
        }

        $name = trim((string) preg_replace('/\s+/', ' ', $name), ' -,.…|');

        // Titles in capitals read better as words.
        if ($name !== '' && mb_strtoupper($name) === $name) {
            $name = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
        }

        return mb_strlen($name) >= 3 ? mb_substr($name, 0, 80) : null;
    }

    /**
     * The distinctive words of a name, for matching.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/[^a-z]+/', strtolower($text)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $w) => strlen($w) >= 3 && ! in_array($w, self::COMMON, true),
        )));
    }
}
