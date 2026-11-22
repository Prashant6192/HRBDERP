<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Support;

/**
 * The photo the packing screen shows for a product that has no approved
 * artwork picture of its own: the brand's catalogue photo, found by the
 * product's name. One photo serves every size of a product; the size is
 * said in words beside it.
 */
final class ProductPhotos
{
    /** Words in a product's name => its photo under public/images/products. Longer names first. */
    private const array PHOTOS = [
        'neem face wash' => 'neem-face-wash.jpg',
        'neem facewash' => 'neem-face-wash.jpg',
        'ratna green' => 'ratna-green.png',
        'lal tel' => 'lal-tel.png',
        'medicated' => 'medicated.png',
        'satreetha' => 'satreetha.png',
        'sukoon' => 'sukoon.png',
        'lauki' => 'lauki.png',
        'green' => 'ratna-green.png',
    ];

    /**
     * The path of the photo, from the site's root, or null when the
     * catalogue has none for this product.
     */
    public static function forName(string $name): ?string
    {
        $name = ' '.trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($name))).' ';

        foreach (self::PHOTOS as $words => $file) {
            if (str_contains($name, " {$words} ")) {
                return "/images/products/{$file}";
            }
        }

        return null;
    }
}
