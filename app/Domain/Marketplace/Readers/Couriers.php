<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Readers;

/**
 * The courier companies that turn up on marketplace labels, and how to
 * recognise each in the text a label prints — sometimes run together with
 * the words next to it ("ValmoPickup01/10").
 */
final class Couriers
{
    /**
     * Canonical name => ways it is printed.
     *
     * @var array<string, list<string>>
     */
    private const array NAMES = [
        'Delhivery' => ['delhivery'],
        'Shadowfax' => ['shadowfax'],
        'Xpress Bees' => ['xpress bees', 'xpressbees'],
        'Valmo' => ['valmo'],
        'Ecom Express' => ['ecom express', 'ecomexpress'],
        'Ekart' => ['e-kart logistics', 'ekart logistics', 'e-kart', 'ekart'],
        'Amazon Shipping' => ['amazon shipping', 'atspl'],
        'Blue Dart' => ['blue dart', 'bluedart'],
        'DTDC' => ['dtdc'],
        'India Post' => ['india post', 'speed post'],
        'Shiprocket' => ['shiprocket'],
    ];

    /**
     * The first courier named in the text.
     */
    public static function find(string $text): ?string
    {
        $haystack = strtolower($text);
        $found = null;
        $at = PHP_INT_MAX;

        foreach (self::NAMES as $name => $spellings) {
            foreach ($spellings as $spelling) {
                $position = strpos($haystack, $spelling);

                if ($position !== false && $position < $at) {
                    $found = $name;
                    $at = $position;
                }
            }
        }

        return $found;
    }

    /**
     * Flipkart-group labels (Flipkart, Myntra) print a route code instead of
     * the courier's name: two letters for the courier, then "_E2E" —
     * "EK_E2E" is Ekart, "DE_E2E-ON-M7" Delhivery.
     *
     * @var array<string, string>
     */
    private const array ROUTE_CODES = [
        'EK' => 'Ekart',
        'DE' => 'Delhivery',
        'XB' => 'Xpress Bees',
        'SF' => 'Shadowfax',
        'EC' => 'Ecom Express',
    ];

    /**
     * The courier a route code such as "DE_E2E-ON-M7" names — read with or
     * without its underscore, as OCR sometimes drops it.
     */
    public static function fromRouteCode(string $text): ?string
    {
        if (preg_match('/\b([A-Z]{2})[ _-]?E2E\b/', strtoupper($text), $m) !== 1) {
            return null;
        }

        return self::ROUTE_CODES[$m[1]] ?? null;
    }

    /**
     * Every courier the ERP knows by name, for choosing one by hand.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::NAMES);
    }

    /**
     * Who carries a parcel, from the shape of its AWB, when the label does
     * not say in words.
     */
    public static function fromAwb(?string $awb): ?string
    {
        return match (true) {
            $awb === null => null,
            str_starts_with($awb, 'VL') => 'Valmo',
            preg_match('/^SF\d+[A-Z]*$/', $awb) === 1 => 'Shadowfax',
            str_starts_with($awb, 'FMP') => 'Ekart',
            str_starts_with($awb, 'MYEC') => 'Ekart',
            default => null,
        };
    }
}
