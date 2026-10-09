<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\LabelBatch;
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
 * The depot manager is assigned to the factory, and the agency's labels
 * land in the depot's store. He still prints them, scans them, cancels
 * one, dispatches the rest and takes a return: whoever works the online
 * orders works them at every facility. The agency stays held to its
 * brand.
 */
class DepotWorksEveryFacilityTest extends TestCase
{
    use RefreshDatabase;

    private Facility $depot;

    private Warehouse $depotFg;

    private Brand $rahatRooh;

    private Product $oil;

    private User $agency;

    private User $shanu;

    private LabelBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('files');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));
        config(['erp.online_orders.cutoff' => '16:00', 'erp.company.timezone' => 'Asia/Kolkata']);

        $factory = Facility::factory()->manufacturing()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Rudrapur Manufacturing Facility']);
        $this->depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Paper Market Warehouse', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->depotFg = $this->depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->seed(ReferenceDataSeeder::class);

        $this->rahatRooh = Brand::query()->where('code', 'RR')->sole();
        $meesho = Marketplace::query()->where('code', 'MEESHO')->sole();

        $this->oil = Product::factory()->create(['name' => 'Rahat Rooh Hair Oil 200 ml', 'stock_uom_id' => Uom::where('code', 'PCS')->sole()->id, 'client_id' => null]);
        $lot = InventoryLot::factory()->forItem($this->oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        app(InventoryLedgerService::class)->receive($this->oil, $this->depotFg, '20', $lot, InventoryTransactionType::StockAdjustmentIn);

        $this->agency = User::factory()->create();
        $this->agency->assignRole(RoleName::EcommerceAgency->value);
        $this->agency->brands()->attach($this->rahatRooh);

        $this->shanu = User::factory()->create(['name' => 'Shanu Kumar']);
        $this->shanu->assignRole(RoleName::WarehouseManager->value);
        app(EmployeeAssignmentService::class)->assign($this->shanu, $factory, null, ['is_primary' => true], null);

        $orders = app(OnlineOrderService::class);
        $orders->mapSku($meesho, $this->rahatRooh, 'Hair oil 200 ml', $this->oil, 1, $this->agency);
        $label = fn (string $awb, int $n) => [
            'awb' => $awb, 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'Hair oil 200 ml', 'qty' => 1,
            'order' => sprintf('2000000000000%05d', $n), 'invoice' => sprintf('fghij%04d', $n), 'total' => '199.00',
            'name' => "Test Customer {$n}", 'state' => 'Bihar',
        ];
        $this->batch = $orders->upload($this->rahatRooh, $meesho, $this->depotFg, [LabelFixtures::meesho([
            $label('VL1000000000001', 1),
            $label('VL1000000000002', 2),
            $label('VL1000000000003', 3),
        ])], $this->agency);
    }

    #[Test]
    public function he_sees_the_depots_labels_and_can_pick_the_depot(): void
    {
        $this->actingAs($this->shanu)->get(route('online-orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->where('batches.0.facility', 'Paper Market Warehouse')
            ->where('facilities', fn ($facilities) => collect($facilities)->pluck('label')->contains('Paper Market Warehouse'))
            ->where('elsewhere', null));
    }

    #[Test]
    public function he_prints_scans_cancels_and_dispatches_them(): void
    {
        $plan = $this->actingAs($this->shanu)->postJson(route('online-orders.print', $this->batch), ['scope' => 'all'])->assertOk()->json();
        $this->assertNotEmpty($plan['parts']);
        $this->assertSame(3, Shipment::query()->where('status', ShipmentStatus::Printed->value)->count());

        $this->assertTrue($this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => 'VL1000000000001'])->assertOk()->json('ok'));
        $this->assertTrue($this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => 'VL1000000000002'])->assertOk()->json('ok'));

        $cancelled = Shipment::query()->where('awb', 'VL1000000000002')->sole();
        $this->actingAs($this->shanu)->postJson(route('floor.pack.cancel', $cancelled), ['reason' => 'Buyer cancelled'])->assertOk();
        $this->assertSame(ShipmentStatus::Cancelled, $cancelled->refresh()->status);

        $packed = Shipment::query()->where('awb', 'VL1000000000001')->sole();
        $this->actingAs($this->shanu)->post(route('online-orders.dispatch'), ['shipment_ids' => [$packed->id]])->assertRedirect();
        $this->assertSame(ShipmentStatus::HandedOver, $packed->refresh()->status);

        $this->actingAs($this->shanu)->get(route('floor.handover'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stores', fn ($stores) => collect($stores)->pluck('value')->contains((string) $this->depotFg->id)));
    }

    #[Test]
    public function he_takes_a_return_into_the_depot(): void
    {
        $orders = app(OnlineOrderService::class);
        $shipment = $orders->pack($orders->findByCode('VL1000000000003'), $this->shanu);
        $orders->dispatch(Shipment::query(), [$shipment->id], $this->shanu);

        $this->actingAs($this->shanu)->get(route('online-orders.returns.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stores', fn ($stores) => collect($stores)->pluck('value')->contains((string) $this->depotFg->id)));

        $this->actingAs($this->shanu)->post(route('online-orders.returns.store'), [
            'shipment_id' => $shipment->id,
            'into_store_id' => $this->depotFg->id,
            'lines' => [['item_id' => $this->oil->id, 'good' => '1', 'damaged' => '0']],
        ])->assertRedirect(route('online-orders.returns.create'));

        $this->assertSame(ShipmentStatus::Returned, $shipment->refresh()->status);
    }

    #[Test]
    public function a_manager_who_also_uploads_as_the_agency_still_prints_and_scans(): void
    {
        // Given the agency role as well, to upload labels himself.
        $this->shanu->assignRole(RoleName::EcommerceAgency->value);

        $this->actingAs($this->shanu)->get(route('online-orders.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('batches', 1)->where('can.restricted', false)->where('can.upload', true));

        $ids = Shipment::query()->pluck('id')->all();
        $this->actingAs($this->shanu)->postJson(route('online-orders.print-selected'), ['shipment_ids' => $ids])->assertOk();
        $this->assertSame(3, Shipment::query()->where('status', ShipmentStatus::Printed->value)->count());
        $this->assertTrue($this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => 'VL1000000000001'])->assertOk()->json('ok'));
        $this->actingAs($this->shanu)->get(route('dashboard'))->assertOk();
    }

    #[Test]
    public function the_agency_is_still_held_to_its_brand(): void
    {
        $other = User::factory()->create();
        $other->assignRole(RoleName::EcommerceAgency->value);
        $other->brands()->attach(Brand::query()->where('code', 'CA')->sole());

        $this->actingAs($other)->get(route('online-orders.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('batches', []));
        $this->actingAs($this->agency)->get(route('online-orders.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('batches', 1)->where('can.restricted', true));

        // And kept to uploading: it cannot print.
        $this->actingAs($this->agency)->postJson(route('online-orders.print-selected'), ['shipment_ids' => Shipment::query()->pluck('id')->all()])
            ->assertForbidden();
    }
}
