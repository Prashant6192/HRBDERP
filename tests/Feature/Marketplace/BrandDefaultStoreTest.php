<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Marketplace\Models\Brand;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Each online brand ships from the Delhi depot's finished goods store unless
 * someone has chosen otherwise, whatever the depot is called.
 */
class BrandDefaultStoreTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_delhi_depot_is_found_even_when_it_is_not_named_paper_market(): void
    {
        $factory = Facility::factory()->manufacturing()->create(['name' => 'Rudrapur Manufacturing Facility', 'city' => 'Rudrapur']);
        Warehouse::factory()->atFacility($factory)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'RDP-FG']);
        $delhi = Facility::factory()->create(['name' => 'Delhi Warehouse', 'city' => 'New Delhi', 'can_manufacture' => false]);
        $delhiFg = Warehouse::factory()->atFacility($delhi)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'DEL-FG']);

        ReferenceDataSeeder::ensureBrands();

        $this->assertSame([$delhiFg->id, $delhiFg->id], Brand::query()->orderBy('code')->pluck('default_warehouse_id')->all());
    }

    #[Test]
    public function a_store_chosen_on_the_brands_screen_is_kept(): void
    {
        $delhi = Facility::factory()->create(['name' => 'Paper Market Depot', 'city' => 'Delhi', 'can_manufacture' => false]);
        Warehouse::factory()->atFacility($delhi)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'PM-FG']);
        $other = Warehouse::factory()->atFacility($delhi)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'PM-FG2']);

        ReferenceDataSeeder::ensureBrands();
        $brand = Brand::query()->where('code', 'CA')->sole();
        $brand->forceFill(['default_warehouse_id' => $other->id])->save();

        ReferenceDataSeeder::ensureBrands();

        $this->assertSame($other->id, $brand->refresh()->default_warehouse_id);
    }

    #[Test]
    public function nothing_is_guessed_when_two_depots_could_be_meant(): void
    {
        foreach (['Okhla Depot', 'Naraina Depot'] as $i => $name) {
            $f = Facility::factory()->create(['name' => $name, 'city' => 'Delhi', 'can_manufacture' => false]);
            Warehouse::factory()->atFacility($f)->ofType(WarehouseType::FinishedGoods)->create(['code' => "D{$i}-FG"]);
        }

        Brand::query()->update(['default_warehouse_id' => null]);
        ReferenceDataSeeder::ensureBrands();

        $this->assertSame(0, Brand::query()->whereNotNull('default_warehouse_id')->count());
    }
}
