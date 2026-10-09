<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Enums\ShipmentStatus;
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
 * The depot's day: a sealed parcel is scanned once (packed, Scanned); the
 * courier's parcels are marked Dispatched in bulk when the courier leaves.
 * A second scan says so; a cancelled order is cancelled with its stock
 * put back. The report reads it all back by day, courier and brand.
 */
class OneScanFlowTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $depotFg;

    private Product $oil;

    private User $shanu;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('files');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));
        config(['erp.company.timezone' => 'Asia/Kolkata', 'erp.online_orders.cutoff' => '16:00']);
        $this->travelTo(CarbonImmutable::parse('2026-09-30 11:00', 'Asia/Kolkata'));

        $depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Paper Market Warehouse', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->depotFg = $depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->seed(ReferenceDataSeeder::class);

        $this->oil = Product::factory()->create(['code' => 'FG-MO-300', 'name' => 'Rahat Rooh Medicated Oil 300ml', 'stock_uom_id' => Uom::where('code', 'PCS')->sole()->id, 'client_id' => null]);
        $lot = InventoryLot::factory()->forItem($this->oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        app(InventoryLedgerService::class)->receive($this->oil, $this->depotFg, '20', $lot, InventoryTransactionType::StockAdjustmentIn);

        $this->shanu = User::factory()->create(['name' => 'Shanu Kumar']);
        $this->shanu->assignRole(RoleName::WarehouseManager->value);
        app(EmployeeAssignmentService::class)->assign($this->shanu, $depot, null, ['is_primary' => true], null);

        $this->boss = User::factory()->create();
        $this->boss->assignRole(RoleName::SuperAdmin->value);

        $agency = User::factory()->create();
        $agency->assignRole(RoleName::EcommerceAgency->value);
        $brand = Brand::query()->where('code', 'RR')->sole();
        $meesho = Marketplace::query()->where('code', 'MEESHO')->sole();
        $label = fn (string $awb, string $courier, int $n) => [
            'awb' => $awb, 'courier' => $courier, 'payment' => 'cod', 'sku' => 'Medicated oil 300 ml', 'qty' => 1,
            'order' => sprintf('20000000000%04d', $n), 'invoice' => sprintf('abcde%04d', $n), 'total' => '199.00', 'name' => "C {$n}", 'state' => 'Delhi',
        ];

        $orders = app(OnlineOrderService::class);
        $orders->upload($brand, $meesho, $this->depotFg, [LabelFixtures::meesho([
            $label('VL0000000000001', 'Valmo', 1),
            $label('VL0000000000002', 'Valmo', 2),
            $label('DL0000000000003', 'Delhivery', 3),
            $label('DL0000000000004', 'Delhivery', 4),
        ])], $agency);
        $orders->mapSku($meesho, $brand, 'Medicated oil 300 ml', $this->oil, 1, $agency);
    }

    private function scan(string $code)
    {
        return $this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => $code]);
    }

    private function onShelf(): string
    {
        return app(StockBalanceService::class)->onHand($this->oil, $this->depotFg)->strippedOfTrailingZeros()->__toString();
    }

    private function parcel(string $awb): Shipment
    {
        return Shipment::query()->where('awb', $awb)->sole();
    }

    #[Test]
    public function one_scan_packs_the_parcel_and_a_second_scan_says_so(): void
    {
        $this->actingAs($this->shanu)->get(route('floor.pack'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('floor/pack')
                ->where('date', '2026-09-30')
                ->where('is_today', true)
                ->where('counts', ['to_scan' => 4, 'scanned' => 0, 'dispatched' => 0, 'cancelled' => 0]));

        $ok = $this->scan('VL0000000000001')->assertOk()->json();
        $this->assertSame('scanned', $ok['result']);

        $parcel = $this->parcel('VL0000000000001');
        $this->assertSame(ShipmentStatus::Packed, $parcel->status);
        $this->assertSame('Scanned', $parcel->status->label());
        $this->assertSame($this->shanu->id, $parcel->packed_by);
        $this->assertSame('19', $this->onShelf());

        // The same label again: "already scanned", nothing taken twice.
        $again = $this->scan('VL0000000000001')->assertOk()->json();
        $this->assertSame('already', $again['result']);
        $this->assertStringContainsString('Already scanned by Shanu Kumar at 30 Sep, 11:00 AM', $again['message']);
        $this->assertStringContainsString('duplicate', $again['message']);
        $this->assertSame('19', $this->onShelf());

        $this->actingAs($this->shanu)->get(route('floor.pack'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.to_scan', 3)->where('counts.scanned', 1));
    }

    #[Test]
    public function the_courier_leaves_and_the_scanned_parcels_are_marked_dispatched(): void
    {
        $this->scan('VL0000000000001');
        $this->scan('VL0000000000002');
        $this->scan('DL0000000000003');

        $this->actingAs($this->shanu)->get(route('online-orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.packed', 3)
                ->where('dispatchable.Valmo', [$this->parcel('VL0000000000001')->id, $this->parcel('VL0000000000002')->id])
                ->has('dispatchable.Delhivery', 1));

        // Valmo picked up: only Valmo's scanned parcels; an unscanned one is skipped.
        $this->actingAs($this->shanu)->post(route('online-orders.dispatch'), [
            'shipment_ids' => [$this->parcel('VL0000000000001')->id, $this->parcel('VL0000000000002')->id, $this->parcel('DL0000000000004')->id],
        ])->assertRedirect();

        $this->assertSame(ShipmentStatus::HandedOver, $this->parcel('VL0000000000001')->status);
        $this->assertSame('Dispatched', $this->parcel('VL0000000000001')->status->label());
        $this->assertSame(ShipmentStatus::Packed, $this->parcel('DL0000000000003')->status);
        $this->assertTrue($this->parcel('DL0000000000004')->status->awaitsPacking(), 'Not scanned, not dispatched.');

        // A dispatched parcel scanned again says so, and can still be cancelled.
        $again = $this->scan('VL0000000000002')->assertOk()->json();
        $this->assertSame('dispatched', $again['result']);
        $this->assertStringContainsString('Already dispatched', $again['message']);

        $this->actingAs($this->shanu)->postJson(route('floor.pack.cancel', $this->parcel('VL0000000000002')))->assertOk();
        $this->assertSame(ShipmentStatus::Cancelled, $this->parcel('VL0000000000002')->status);
        $this->assertSame('18', $this->onShelf(), 'One of three back on the shelf.');
    }

    #[Test]
    public function a_cancelled_order_found_at_pickup_puts_the_stock_back(): void
    {
        $this->scan('DL0000000000003')->assertOk();
        $this->assertSame('19', $this->onShelf());
        $parcel = $this->parcel('DL0000000000003');

        $this->actingAs($this->shanu)->postJson(route('floor.pack.cancel', $parcel))->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(ShipmentStatus::Cancelled, $parcel->refresh()->status);
        $this->assertSame('20', $this->onShelf(), 'Back on the shelf.');

        // Scanned again later: stop, it is cancelled.
        $stop = $this->scan('DL0000000000003')->assertStatus(422)->json();
        $this->assertStringContainsString('was cancelled', $stop['message']);

        // The office's Cancel an order works the same on a scanned parcel.
        $this->scan('DL0000000000004')->assertOk();
        $this->actingAs($this->boss)->post(route('online-orders.parcels.cancel', $this->parcel('DL0000000000004')), ['reason' => 'Cancelled on Meesho'])->assertRedirect();
        $this->assertSame('20', $this->onShelf());
    }

    #[Test]
    public function the_day_can_be_changed_on_the_scan_screen(): void
    {
        $this->actingAs($this->shanu)->get(route('floor.pack', ['date' => '2026-09-29']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('date', '2026-09-29')
                ->where('is_today', false)
                ->where('counts.to_scan', 0));
    }

    #[Test]
    public function the_report_reads_the_day_back_and_downloads_as_excel(): void
    {
        $this->scan('VL0000000000001');
        $this->scan('VL0000000000002');
        $this->actingAs($this->shanu)->post(route('online-orders.dispatch'), ['shipment_ids' => [$this->parcel('VL0000000000001')->id]]);
        $this->scan('DL0000000000003');
        $this->actingAs($this->shanu)->postJson(route('floor.pack.cancel', $this->parcel('DL0000000000003')));

        $this->actingAs($this->boss)->get(route('online-orders.report', ['from' => '2026-09-30', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('online-orders/report')
                ->where('report.totals.uploaded', 4)
                ->where('report.totals.in_packing', 1)
                ->where('report.totals.scanned', 1)
                ->where('report.totals.dispatched', 1)
                ->where('report.totals.cancelled', 1)
                ->where('report.couriers.0.key', 'Delhivery')
                ->where('report.couriers.1.key', 'Valmo')
                ->where('report.couriers.1.dispatched', 1)
                ->where('report.products.0.code', 'FG-MO-300')
                ->where('report.products.0.pieces', '2')
                ->where('report.scanners.0.name', 'Shanu Kumar')
                ->where('report.scanners.0.scanned', 2)
                ->has('report.look_again', 1)
                ->where('report.look_again.0.what', 'Cancelled')
                ->where('report.look_again.0.stock_back', true));

        $excel = $this->actingAs($this->boss)->get(route('online-orders.report.excel', ['from' => '2026-09-30', 'to' => '2026-09-30']))->assertOk();
        $this->assertStringContainsString('online-orders-2026-09-30.xlsx', (string) $excel->headers->get('content-disposition'));

        // The agency does not see reports.
        $agency = User::query()->whereHas('roles', fn ($q) => $q->where('name', RoleName::EcommerceAgency->value))->first();
        $this->actingAs($agency)->get(route('online-orders.report'))->assertRedirect();
    }
}
