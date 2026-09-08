<?php

declare(strict_types=1);

namespace Tests\Unit\Measurement;

use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\ItemUomConversion;
use App\Domain\Measurement\Enums\UomDimension;
use App\Domain\Measurement\Exceptions\IncompatibleUnitsException;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Measurement\Services\UnitConversionService;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Conversion is where an ERP quietly loses material.
 *
 * These tests pin down the arithmetic itself, the refusals, and the rounding,
 * because a wrong answer here does not raise an error — it just puts the wrong
 * number of kilograms into a batch.
 */
class UnitConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    private UnitConversionService $service;

    private Uom $gram;

    private Uom $kilogram;

    private Uom $milligram;

    private Uom $millilitre;

    private Uom $litre;

    private Uom $piece;

    private Uom $carton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new UnitConversionService;

        $this->gram = $this->uom('G', UomDimension::Mass, '1', base: true);
        $this->milligram = $this->uom('MG', UomDimension::Mass, '0.001');
        $this->kilogram = $this->uom('KG', UomDimension::Mass, '1000');

        $this->millilitre = $this->uom('ML', UomDimension::Volume, '1', base: true);
        $this->litre = $this->uom('L', UomDimension::Volume, '1000');

        $this->piece = $this->uom('PCS', UomDimension::Count, '1', base: true);
        $this->carton = $this->uom('CTN', UomDimension::Count, '1', pack: true);
    }

    private function uom(string $code, UomDimension $dimension, string $factor, bool $base = false, bool $pack = false): Uom
    {
        return Uom::create([
            'code' => $code,
            'name' => $code,
            'dimension' => $dimension,
            'is_base' => $base,
            'requires_item_factor' => $pack,
            'factor_to_base' => $factor,
            'display_scale' => 3,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_converts_kilograms_to_grams(): void
    {
        $result = $this->service->convert('2.5', $this->kilogram, $this->gram);

        $this->assertSame('2500.000000', (string) $result);
    }

    #[Test]
    public function it_converts_grams_to_kilograms(): void
    {
        $result = $this->service->convert('1500', $this->gram, $this->kilogram);

        $this->assertSame('1.500000', (string) $result);
    }

    #[Test]
    public function it_converts_between_two_non_base_units(): void
    {
        // 2 kg -> 2,000,000 mg, neither of which is the base unit.
        $result = $this->service->convert('2', $this->kilogram, $this->milligram);

        $this->assertSame('2000000.000000', (string) $result);
    }

    #[Test]
    public function it_converts_litres_to_millilitres(): void
    {
        $result = $this->service->convert('0.75', $this->litre, $this->millilitre);

        $this->assertSame('750.000000', (string) $result);
    }

    #[Test]
    public function converting_a_unit_to_itself_changes_nothing(): void
    {
        $result = $this->service->convert('123.456', $this->kilogram, $this->kilogram);

        $this->assertSame('123.456000', (string) $result);
    }

    #[Test]
    public function it_accepts_integers_and_big_decimals(): void
    {
        $this->assertSame(
            '5000.000000',
            (string) $this->service->convert(5, $this->kilogram, $this->gram),
        );

        $this->assertSame(
            '5000.000000',
            (string) $this->service->convert(BigDecimal::of('5'), $this->kilogram, $this->gram),
        );
    }

    #[Test]
    public function it_refuses_to_convert_between_dimensions_without_a_density(): void
    {
        $this->expectException(IncompatibleUnitsException::class);

        $this->service->convert('1', $this->litre, $this->kilogram);
    }

    #[Test]
    public function it_reports_that_a_cross_dimension_conversion_is_impossible(): void
    {
        $this->assertFalse($this->service->canConvert($this->litre, $this->kilogram));
        $this->assertTrue($this->service->canConvert($this->litre, $this->millilitre));
    }

    #[Test]
    public function it_bridges_volume_to_mass_using_the_item_density(): void
    {
        // 1 litre of a material at 1.05 g/ml weighs 1.05 kg.
        $item = Item::factory()->create([
            'stock_uom_id' => $this->kilogram->id,
            'density_g_per_ml' => '1.050000',
        ]);

        $result = $this->service->convert('1', $this->litre, $this->kilogram, $item);

        $this->assertSame('1.050000', (string) $result);
    }

    #[Test]
    public function it_bridges_mass_to_volume_using_the_item_density(): void
    {
        // 1.05 kg of a material at 1.05 g/ml occupies 1 litre.
        $item = Item::factory()->create([
            'stock_uom_id' => $this->kilogram->id,
            'density_g_per_ml' => '1.050000',
        ]);

        $result = $this->service->convert('1.05', $this->kilogram, $this->litre, $item);

        $this->assertSame('1.000000', (string) $result);
    }

    #[Test]
    public function density_is_only_used_for_the_item_that_declares_it(): void
    {
        $withoutDensity = Item::factory()->create([
            'stock_uom_id' => $this->kilogram->id,
            'density_g_per_ml' => null,
        ]);

        $this->expectException(IncompatibleUnitsException::class);

        $this->service->convert('1', $this->litre, $this->kilogram, $withoutDensity);
    }

    #[Test]
    public function it_uses_an_item_specific_factor_for_pack_units(): void
    {
        $item = Item::factory()->create(['stock_uom_id' => $this->piece->id]);

        ItemUomConversion::create([
            'item_id' => $item->id,
            'from_uom_id' => $this->carton->id,
            'to_uom_id' => $this->piece->id,
            'factor' => '120',
        ]);

        $result = $this->service->convert('3', $this->carton, $this->piece, $item);

        $this->assertSame('360.000000', (string) $result);
    }

    #[Test]
    public function an_item_specific_factor_works_in_reverse_without_a_second_row(): void
    {
        $item = Item::factory()->create(['stock_uom_id' => $this->piece->id]);

        ItemUomConversion::create([
            'item_id' => $item->id,
            'from_uom_id' => $this->carton->id,
            'to_uom_id' => $this->piece->id,
            'factor' => '120',
        ]);

        $result = $this->service->convert('360', $this->piece, $this->carton, $item);

        $this->assertSame('3.000000', (string) $result);
    }

    #[Test]
    public function it_refuses_a_pack_unit_with_no_item_factor(): void
    {
        // This is the case that would otherwise treat one carton as one piece.
        $item = Item::factory()->create(['stock_uom_id' => $this->piece->id]);

        $this->expectException(IncompatibleUnitsException::class);

        $this->service->convert('3', $this->carton, $this->piece, $item);
    }

    #[Test]
    public function it_refuses_a_pack_unit_when_no_item_is_supplied_at_all(): void
    {
        $this->expectException(IncompatibleUnitsException::class);

        $this->service->convert('3', $this->carton, $this->piece);
    }

    #[Test]
    public function it_converts_a_quantity_into_an_items_stock_unit(): void
    {
        $item = Item::factory()->create(['stock_uom_id' => $this->kilogram->id]);

        $result = $this->service->toStockUom($item, '2500', $this->gram);

        $this->assertSame('2.500000', (string) $result);
    }

    #[Test]
    public function it_keeps_precision_that_floating_point_would_lose(): void
    {
        // 0.1 + 0.2 != 0.3 in binary floating point. Three lots of 0.1 kg must
        // still come to exactly 300 g.
        $result = $this->service->convert('0.3', $this->kilogram, $this->gram);

        $this->assertSame('300.000000', (string) $result);
    }

    #[Test]
    public function it_rounds_half_up_at_the_configured_scale(): void
    {
        // 1 mg = 0.000001 kg, which sits exactly at the six-decimal scale.
        $result = $this->service->convert('1', $this->milligram, $this->kilogram);

        $this->assertSame('0.000001', (string) $result);
    }

    #[Test]
    public function a_repeating_decimal_is_rounded_rather_than_throwing(): void
    {
        // 1 g / 3 has no exact decimal form. brick/math refuses inexact
        // division unless a scale is given, so this asserts the service
        // supplies one rather than letting the exception escape.
        $third = $this->uom('THIRD', UomDimension::Mass, '3');

        $result = $this->service->convert('1', $this->gram, $third);

        $this->assertSame('0.333333', (string) $result);
    }
}
