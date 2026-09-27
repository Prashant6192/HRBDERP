<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Inventory\Services\StockAlertService;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The raw materials that came in on Rudrapur's counting sheet get their
 * stock control: order, and critically low, at a third of what was
 * counted; ten days' lead time; the HSN code where the tariff is clear.
 */
class SheetMaterialStockLevelsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_third_of_the_counted_stock_becomes_the_reorder_and_critical_level(): void
    {
        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(RoleName::SuperAdmin->value);
        $rudrapur = Facility::factory()->manufacturing()->withStores([WarehouseType::RawMaterial])->create(['opening_stock_enabled' => true]);
        $rm = $rudrapur->stores()->firstOrFail();
        $kg = Uom::where('code', 'KG')->sole();

        $line = fn (string $code, string $name, string $qty) => [
            'item_id' => '', 'new_item' => ['code' => $code, 'name' => $name], 'quantity' => $qty,
            'uom_id' => (string) $kg->id, 'batch_number' => "B-{$code}",
        ];

        $this->actingAs($admin)->post(route('facilities.opening-stock.store', $rudrapur), [
            'warehouse_id' => (string) $rm->id,
            'lines' => [$line('GR', 'Glycerine', '60'), $line('CAPB', 'Cocamido Propyl Betaine', '413'), $line('NC', 'Nano Oil Control', '2.9'), $line('LA', 'Lactic Acid', '28')],
        ])->assertSessionHasNoErrors();

        // Someone already set Lactic Acid's levels and HSN by hand.
        RawMaterial::query()->where('code', 'LA')->update(['reorder_level' => '5', 'minimum_stock' => '2', 'hsn_code' => '2918']);
        // A material typed in on its own page is not touched.
        $other = RawMaterial::factory()->create(['code' => 'OTHER', 'stock_uom_id' => $kg->id, 'reorder_level' => null, 'lead_time_days' => null]);

        (require database_path('migrations/2026_09_27_130000_fill_stock_levels_and_hsn_for_sheet_raw_materials.php'))->up();

        $gr = RawMaterial::query()->where('code', 'GR')->sole();
        $this->assertEquals(20, (float) $gr->reorder_level);
        $this->assertEquals(20, (float) $gr->minimum_stock);
        $this->assertSame(10, $gr->lead_time_days);
        $this->assertSame('29054500', $gr->hsn_code);

        $capb = RawMaterial::query()->where('code', 'CAPB')->sole();
        $this->assertEquals(137.667, (float) $capb->reorder_level);
        $this->assertSame('34024900', $capb->hsn_code);

        $this->assertNull(RawMaterial::query()->where('code', 'NC')->sole()->hsn_code, 'A trade blend is left for the purchase bill.');

        $la = RawMaterial::query()->where('code', 'LA')->sole();
        $this->assertEquals(5, (float) $la->reorder_level, 'Figures typed in by hand stay.');
        $this->assertEquals(2, (float) $la->minimum_stock);
        $this->assertSame('2918', $la->hsn_code);
        $this->assertSame(10, $la->lead_time_days);

        $this->assertNull($other->refresh()->lead_time_days);

        // Down to a third, the store dashboard calls it critically low.
        $alerts = app(StockAlertService::class);
        $this->assertSame(StockAlertLevel::Critical, $alerts->levelFor($gr, '20'));
        $this->assertNotSame(StockAlertLevel::Critical, $alerts->levelFor($gr, '21'));
    }
}
