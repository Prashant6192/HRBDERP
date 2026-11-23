<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domain\Marketplace\Support\PackCheck;
use App\Domain\Marketplace\Support\ProductPhotos;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PackCheckNamesTest extends TestCase
{
    #[Test]
    public function a_products_size_is_said_apart_from_its_name(): void
    {
        $this->assertSame(['Rahat Rooh Medicated Oil', '300 ml'], PackCheck::nameAndSize('Rahat Rooh Medicated Oil 300ml'));
        $this->assertSame(['Satreetha Shampoo', '1 l'], PackCheck::nameAndSize('Satreetha Shampoo (1 Ltr)'));
        $this->assertSame(['Lal Tel', '100 ml'], PackCheck::nameAndSize('Lal Tel - 100 ML'));
        $this->assertSame(['Cleanse Hydrating Facewash', null], PackCheck::nameAndSize('Cleanse Hydrating Facewash'));
    }

    #[Test]
    public function the_catalogue_photo_is_found_by_the_products_name(): void
    {
        $this->assertSame('/images/products/medicated.png', ProductPhotos::forName('Rahat Rooh Medicated Oil 500ml'));
        $this->assertSame('/images/products/ratna-green.png', ProductPhotos::forName('Ratna Green Hair Oil 200 ml'));
        $this->assertSame('/images/products/lal-tel.png', ProductPhotos::forName('Ayurvedic LAL TEL 100ml'));
        $this->assertSame('/images/products/neem-face-wash.jpg', ProductPhotos::forName('Neem Face Wash 100 ml'));
        $this->assertNull(ProductPhotos::forName('Ratna Cool Hair Oil 300ml'));
        $this->assertSame('/images/products/ratna-green.png', ProductPhotos::forName('Rahat Rooh Ayurveda Ratan 300ml'));
        $this->assertSame('/images/products/ratna-green.png', ProductPhotos::forName('Rahat Rooh Ayurveda Ratan 500ml'));
        $this->assertNull(ProductPhotos::forName('Ayurveda Ratan Green Hair Oil'));
        $this->assertSame('/images/products/cleanse-kasvi-hair-oil.webp', ProductPhotos::forName('Cleanse Ayurveda Kasvi Hair Oil'));
        $this->assertSame('/images/products/cleanse-hydra-smooth-shampoo.webp', ProductPhotos::forName('Cleanse Ayurveda Hydra Smooth Shampoo'));
        $this->assertSame('/images/products/cleanse-face-moisturiser.webp', ProductPhotos::forName('Cleanse Ayurveda Face Moisturiser'));
        $this->assertSame('/images/products/cleanse-dandruff-shampoo.webp', ProductPhotos::forName('Cleanse Dandruff Care Shampoo 250 ml'));
        $this->assertSame('/images/products/cleanse-hydrating-facewash.webp', ProductPhotos::forName('Cleanse Ayurveda Hydrating Facewash 100 ml'));
        $this->assertSame('/images/products/cleanse-acne-care-facewash.webp', ProductPhotos::forName('Cleanse Oil Control Facewash 100 ml'));
        $this->assertSame('/images/products/cleanse-dandruff-shampoo.webp', ProductPhotos::forName('Cleanse Ayurveda Oil Control Shampoo'));
        $this->assertSame('/images/products/cleanse-exfoliating-body-wash.webp', ProductPhotos::forName('Cleanse Ayurveda Exfoliating Bodywash 250 ml'));
        $this->assertSame('/images/products/cleanse-hydrating-facewash.webp', ProductPhotos::forName('Cleanse Hydrating Facewash'));

        foreach (['medicated.png', 'sukoon.png', 'ratna-green.png', 'satreetha.png', 'lauki.png', 'lal-tel.png', 'neem-face-wash.jpg', 'cleanse-kasvi-hair-oil.webp', 'cleanse-hydra-smooth-shampoo.webp', 'cleanse-dandruff-shampoo.webp', 'cleanse-face-moisturiser.webp', 'cleanse-hydrating-facewash.webp', 'cleanse-acne-care-facewash.webp', 'cleanse-exfoliating-body-wash.webp'] as $file) {
            $this->assertFileExists(dirname(__DIR__, 3)."/public/images/products/{$file}");
        }
    }
}
