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
