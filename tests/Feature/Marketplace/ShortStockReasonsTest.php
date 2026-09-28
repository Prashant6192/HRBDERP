<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeLabelReader;
use Tests\Support\LabelFixtures;
use Tests\TestCase;

/**
 * Paper Market has "enough" 300 ml oil, yet the parcels say short. The
 * screen says why — booked under a look-alike product, still at Rudrapur,
 * waiting for QC — and the parcels are held the moment the right stock
 * lands in the store.
 */
class ShortStockReasonsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $depotFg;

    private Warehouse $factoryFg;

    private Product $oil;

    private User $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));
        config(['erp.company.timezone' => 'Asia/Kolkata']);

        $factory = Facility::factory()->manufacturing()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Rudrapur Manufacturing Facility']);
        $this->factoryFg = $factory->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Paper Market Warehouse', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->depotFg = $depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->seed(ReferenceDataSeeder::class);

        $pcs = Uom::where('code', 'PCS')->sole();
        $this->oil = Product::factory()->create(['code' => 'FG-MO-300', 'name' => 'Rahat Rooh Medicated Oil 300ml', 'stock_uom_id' => $pcs->id, 'client_id' => null]);

        $this->dispatcher = User::factory()->create();
        $this->dispatcher->assignRole(RoleName::DispatchManager->value);

        $agency = User::factory()->create();
        $agency->assignRole(RoleName::EcommerceAgency->value);
        $brand = Brand::query()->where('code', 'RR')->sole();
        $agency->brands()->attach($brand);
        $meesho = Marketplace::query()->where('code', 'MEESHO')->sole();

        $orders = app(OnlineOrderService::class);
        $orders->upload($brand, $meesho, $this->depotFg, [LabelFixtures::meesho([
            ['awb' => 'VL0000000000001', 'courier' => 'Delhivery', 'payment' => 'cod', 'sku' => 'Medicated oil 300 ml', 'qty' => 2,
                'order' => '200000000000001', 'invoice' => 'abcde0001', 'total' => '404.00', 'name' => 'A Customer', 'state' => 'Delhi'],
        ])], $agency);
        $orders->mapSku($meesho, $brand, 'Medicated oil 300 ml', $this->oil, 1, $agency);
    }

    private function receive(Product $product, Warehouse $store, string $qty, LotQcStatus $qc = LotQcStatus::Approved): void
    {
        $lot = InventoryLot::factory()->forItem($product)->create(['qc_status' => $qc, 'expiry_at' => now()->addYear()]);
        app(InventoryLedgerService::class)->receive($product, $store, $qty, $lot, InventoryTransactionType::StockAdjustmentIn);
    }

    #[Test]
    public function the_screen_says_where_the_stock_is_and_the_parcel_is_held_when_it_lands(): void
    {
        $this->assertSame(StockState::Short, Shipment::query()->sole()->stock_state);

        // Opening stock booked under a second product of nearly the same name.
        $twin = Product::factory()->create(['code' => 'FG-0042', 'name' => 'Medicated oil 300 ml', 'stock_uom_id' => $this->oil->stock_uom_id]);
        $this->receive($twin, $this->depotFg, '120');
        $this->receive($this->oil, $this->factoryFg, '40');
        $this->receive($this->oil, $this->depotFg, '5', LotQcStatus::Pending);

        $this->assertSame(StockState::Short, Shipment::query()->sole()->stock_state);

        $this->actingAs($this->dispatcher)->get(route('online-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('shortfall', 1)
                ->where('shortfall.0.code', 'FG-MO-300')
                ->where('shortfall.0.store', $this->depotFg->name)
                ->where('shortfall.0.needed', '2')
                ->where('shortfall.0.free', '0')
                ->where('shortfall.0.why', [
                    "5 PCS in {$this->depotFg->name} is waiting for QC — pass the batch to use it.",
                    "Stock is in another store: 40 PCS in {$this->factoryFg->name} (Rudrapur Manufacturing Facility). Move it here with a stock transfer.",
                    "Medicated oil 300 ml (FG-0042) has 120 in {$this->depotFg->name}. If that is the same product, the SKU is mapped to the wrong one — fix it in SKU mapping, or book the stock under FG-MO-300.",
                ]));

        // The right product lands in the store: held at once.
        $this->receive($this->oil, $this->depotFg, '10');

        $this->assertSame(StockState::Reserved, Shipment::query()->sole()->stock_state);
        $this->actingAs($this->dispatcher)->get(route('online-orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('shortfall', []));
    }

    #[Test]
    public function one_button_checks_the_whole_day_again(): void
    {
        // Stock that was already there but not tried (as before this fix).
        $this->receive($this->oil, $this->depotFg, '10');
        Shipment::query()->update(['stock_state' => StockState::Short->value]);

        $this->actingAs($this->dispatcher)->post(route('online-orders.hold-all'), ['date' => now('Asia/Kolkata')->toDateString()])
            ->assertRedirect();

        $this->assertSame(StockState::Reserved, Shipment::query()->sole()->stock_state);
    }
}
