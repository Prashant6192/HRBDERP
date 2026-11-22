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
    /** Words in a product's name => its photo under public/images/products, null for none. First match wins. */
    private const array PHOTOS = [
        // The single green bottle sold on its own has a photo of its own
        // still to come; the Ratan boxes (300 ml, 500 ml) are the green box.
        'ratan green' => null,
        'neem face wash' => 'neem-face-wash.jpg',
        'neem facewash' => 'neem-face-wash.jpg',
        'ayurveda ratan' => 'ratna-green.png',
        'ratna green' => 'ratna-green.png',
        'lal tel' => 'lal-tel.png',
        'medicated' => 'medicated.png',
        'satreetha' => 'satreetha.png',
        'sukoon' => 'sukoon.png',
        'lauki' => 'lauki.png',
        // Cleanse.
        'hydrating facewash' => 'cleanse-hydrating-facewash.webp',
        'hydrating face wash' => 'cleanse-hydrating-facewash.webp',
        'acne' => 'cleanse-acne-care-facewash.webp',
        // Sold as the Acne Care Facewash, for oily skin.
        'oil control facewash' => 'cleanse-acne-care-facewash.webp',
        'oil control face wash' => 'cleanse-acne-care-facewash.webp',
        'kasvi' => 'cleanse-kasvi-hair-oil.webp',
        'hydra smooth' => 'cleanse-hydra-smooth-shampoo.webp',
        'dandruff' => 'cleanse-dandruff-shampoo.webp',
        'moisturiser' => 'cleanse-face-moisturiser.webp',
        'moisturizer' => 'cleanse-face-moisturiser.webp',
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
                return $file === null ? null : "/images/products/{$file}";
            }
        }

        return null;
    }
}
