<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use App\Domain\Marketplace\Support\SkuReading;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading an SKU and its label title in plain words, and suggesting the
 * product it is — from SKUs and titles shaped as the marketplaces print
 * them.
 */
class SkuReadingTest extends TestCase
{
    /**
     * @return list<array{id: int, name: string, pack_of: int|null, pack_quantity: int|null}>
     */
    private function products(): array
    {
        return [
            ['id' => 1, 'name' => 'Rahat Rooh Lauki Oil 200ml', 'pack_of' => null, 'pack_quantity' => null],
            ['id' => 2, 'name' => 'Rahat Rooh Lauki Oil 300ml', 'pack_of' => null, 'pack_quantity' => null],
            ['id' => 3, 'name' => 'Rahat Rooh Satreetha Shampoo 500ml', 'pack_of' => null, 'pack_quantity' => null],
            ['id' => 4, 'name' => 'Rahat Rooh Medicated Oil 300ml', 'pack_of' => null, 'pack_quantity' => null],
            ['id' => 5, 'name' => 'Rahat Rooh Medicated Oil 500ml', 'pack_of' => null, 'pack_quantity' => null],
            ['id' => 6, 'name' => 'Rahat Rooh Lauki Oil 300ml (Pack of 2)', 'pack_of' => 2, 'pack_quantity' => 2],
        ];
    }

    #[Test]
    public function a_title_reads_as_the_product_its_size_and_its_pack(): void
    {
        $r = SkuReading::of('RR-LHK-HO-001 (300ML-2PacK)', 'Rahat Rooh Lauki Oil Herbal Hair Oil for Strong, Thick & Healthy Hai', 'Rahat Rooh');

        $this->assertSame(['Lauki Oil Herbal Hair Oil', '300 ml', 2], [$r->name, $r->size, $r->pack]);
        $this->assertSame(['id' => 6, 'pieces' => 1], $r->suggest($this->products()), 'The pack product, taken once.');
    }

    #[Test]
    public function without_a_pack_product_two_singles_are_suggested(): void
    {
        $r = SkuReading::of('RR-LHK-HO-001 (300ML-2PacK)', 'Rahat Rooh Lauki Oil', 'Rahat Rooh');

        $this->assertSame(['id' => 2, 'pieces' => 2], $r->suggest(array_slice($this->products(), 0, 5)));
    }

    #[Test]
    public function the_size_tells_two_sizes_of_one_product_apart(): void
    {
        $r = SkuReading::of('Medicated_Oil_300ml-MFN', 'Rahat Rooh Ayurvedic Medicated Oil 300ml for Headache & Migraine Relief | Herbal Oil', 'Rahat Rooh');

        $this->assertSame(['Ayurvedic Medicated Oil', '300 ml', null], [$r->name, $r->size, $r->pack]);
        $this->assertSame(['id' => 4, 'pieces' => 1], $r->suggest($this->products()));
    }

    #[Test]
    public function with_no_size_two_sizes_are_not_guessed_between(): void
    {
        $r = SkuReading::of('67962894614801', '67962894614801 Rahat Rooh Lauki Oil Herbal Hair Oil for Strong, Thick & Healthy Hai', 'Rahat Rooh');

        $this->assertSame(['Lauki Oil Herbal Hair Oil', null], [$r->name, $r->size]);
        $this->assertNull($r->suggest($this->products()));
    }

    #[Test]
    public function a_title_in_capitals_reads_as_words(): void
    {
        $r = SkuReading::of('RTTHHROL102020171', 'RAHAT ROOH AYURVEDA RATNA EXTRA COOL OIL- 200 ml, Size: 200 ML (150-200 ML)', 'Rahat Rooh');

        $this->assertSame(['Ayurveda Ratna Extra Cool Oil', '200 ml'], [$r->name, $r->size]);
    }
}
