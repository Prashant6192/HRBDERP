<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Enums\ShipmentStatus;
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
use App\Domain\Warehousing\Services\EmployeeAssignmentService;
use App\Models\User;
use Carbon\CarbonImmutable;
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
 * Online orders → Catch up. Labels printed on 1 and 2 October went out
 * with the courier without being scanned; on the 25th the depot marks the
 * ticked ones packed and dispatched, dated the day of their labels, and
 * the shelf comes right.
 */
class CatchUpTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $depotFg;

    private Product $oil;

    private User $shanu;

    private User $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('files');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));
        config(['erp.company.timezone' => 'Asia/Kolkata', 'erp.online_orders.cutoff' => '16:00']);

        $depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Paper Market Facility', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->depotFg = $depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->seed(ReferenceDataSeeder::class);

        $this->oil = Product::factory()->create(['code' => 'RR-MED-500', 'name' => 'Rahat Rooh Medicated Oil 500ml', 'stock_uom_id' => Uom::where('code', 'PCS')->sole()->id, 'client_id' => null]);
        $lot = InventoryLot::factory()->forItem($this->oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => '2028-01-01']);
        app(InventoryLedgerService::class)->receive($this->oil, $this->depotFg, '3', $lot, InventoryTransactionType::StockAdjustmentIn);

        $this->agency = User::factory()->create();
        $this->agency->assignRole(RoleName::EcommerceAgency->value);
        $this->agency->brands()->attach(Brand::query()->where('code', 'RR')->sole());

        $this->shanu = User::factory()->create(['name' => 'Shanu Kumar']);
        $this->shanu->assignRole(RoleName::WarehouseManager->value);
        app(EmployeeAssignmentService::class)->assign($this->shanu, $depot, null, ['is_primary' => true], null);

        $brand = Brand::query()->where('code', 'RR')->sole();
        $meesho = Marketplace::query()->where('code', 'MEESHO')->sole();
        $orders = app(OnlineOrderService::class);
        $orders->mapSku($meesho, $brand, 'Medicated oil 500 ml', $this->oil, 1, $this->agency);

        $label = fn (string $awb, int $n) => [
            'awb' => $awb, 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'Medicated oil 500 ml', 'qty' => 1,
            'order' => sprintf('2000000000000%05d', $n), 'invoice' => sprintf('fghij%04d', $n), 'total' => '199.00',
            'name' => "Test Customer {$n}", 'state' => 'Bihar',
        ];

        // Two parcels on 1 October, two on 2 October: one more than the shelf holds.
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Asia/Kolkata'));
        $orders->upload($brand, $meesho, $this->depotFg, [LabelFixtures::meesho([$label('VL1000000000001', 1), $label('VL1000000000002', 2)], 'oct1.pdf')], $this->agency);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
        $orders->upload($brand, $meesho, $this->depotFg, [LabelFixtures::meesho([$label('VL1000000000003', 3), $label('VL1000000000004', 4)], 'oct2.pdf')], $this->agency);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00', 'Asia/Kolkata'));
    }

    #[Test]
    public function the_parcels_not_scanned_since_the_first_are_listed_by_day(): void
    {
        $this->actingAs($this->shanu)->get(route('online-orders.catch-up'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('online-orders/catch-up')
                ->where('from', '2026-10-01')
                ->where('to', '2026-10-25')
                ->has('parcels', 4)
                ->where('parcels.0.day', '2026-10-01')
                ->where('parcels.3.day', '2026-10-02')
                ->where('parcels.0.contents', 'Rahat Rooh Medicated Oil 500ml × 1')
                ->where('parcels', fn ($parcels) => collect($parcels)->where('stock_state', 'reserved')->count() === 3
                    && collect($parcels)->where('stock_state', 'short')->count() === 1));
    }

    #[Test]
    public function ticked_parcels_are_packed_and_dispatched_on_the_day_of_their_labels(): void
    {
        $ids = Shipment::query()->orderBy('id')->pluck('id')->all();

        $result = $this->actingAs($this->shanu)->postJson(route('online-orders.catch-up.store'), ['shipment_ids' => $ids])->assertOk()->json();

        $this->assertSame(3, $result['done']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('VL1000000000004', $result['skipped'][0]['reference']);
        $this->assertStringContainsString('Not enough', $result['skipped'][0]['why']);

        $first = Shipment::query()->where('awb', 'VL1000000000001')->sole();
        $this->assertSame(ShipmentStatus::HandedOver, $first->status);
        $this->assertSame(StockState::Consumed, $first->stock_state);
        $this->assertSame('catchup', $first->pack_method);
        $this->assertSame('2026-10-01 18:00', $first->packed_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-01 18:00', $first->handed_over_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));

        $sale = InventoryTransaction::query()->where('type', InventoryTransactionType::MarketplaceSale->value)->where('reference_id', $first->id)->sole();
        $this->assertSame('2026-10-01', $sale->transacted_at->timezone('Asia/Kolkata')->toDateString());

        $this->assertSame('0', (string) app(StockBalanceService::class)->onHand($this->oil, $this->depotFg)->strippedOfTrailingZeros());
        $this->assertSame(ShipmentStatus::Uploaded, Shipment::query()->where('awb', 'VL1000000000004')->sole()->status);

        // Run again: nothing is taken twice.
        $again = $this->actingAs($this->shanu)->postJson(route('online-orders.catch-up.store'), ['shipment_ids' => $ids])->json();
        $this->assertSame(0, $again['done']);
    }

    #[Test]
    public function an_unticked_parcel_is_left_alone(): void
    {
        $one = Shipment::query()->where('awb', 'VL1000000000002')->sole();

        $this->actingAs($this->shanu)->postJson(route('online-orders.catch-up.store'), ['shipment_ids' => [$one->id]])->assertOk();

        $this->assertSame(1, Shipment::query()->where('status', ShipmentStatus::HandedOver->value)->count());
        $this->assertSame(ShipmentStatus::HandedOver, $one->refresh()->status);
    }

    #[Test]
    public function only_the_depot_and_the_office_catch_up(): void
    {
        $this->actingAs($this->agency)->get(route('online-orders.catch-up'))->assertRedirect(route('online-orders.index'));
        $this->actingAs($this->agency)->postJson(route('online-orders.catch-up.store'), ['shipment_ids' => [1]])->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->assignRole(RoleName::MarketingManager->value);
        $this->actingAs($viewer)->get(route('online-orders.catch-up'))->assertForbidden();

        $this->assertSame(0, Shipment::query()->where('status', ShipmentStatus::HandedOver->value)->count());
    }
}
