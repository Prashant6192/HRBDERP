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
 * A depot that does not scan each parcel at packing: stock leaves when the
 * labels are uploaded, the morning's pick list says what to take off the
 * racks, and at the week's end what is back in hand is scanned in again as
 * a cancelled order or a return.
 */
class StockOnUploadTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $depotFg;

    private Product $oil;

    private Brand $rahatRooh;

    private Marketplace $meesho;

    private User $office;

    private OnlineOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('files');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));

        $depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])
            ->create(['name' => 'Paper Market Depot', 'gstin' => '07AAAAA0000A1Z5', 'can_dispatch' => true, 'takes_stock_on_upload' => true]);
        $this->depotFg = $depot->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();

        $this->seed(ReferenceDataSeeder::class);
        $this->rahatRooh = Brand::query()->where('code', 'RR')->sole();
        $this->meesho = Marketplace::query()->where('code', 'MEESHO')->sole();

        $this->oil = Product::factory()->create(['name' => 'Rahat Rooh Hair Oil 200 ml', 'stock_uom_id' => Uom::where('code', 'PCS')->sole()->id, 'client_id' => null]);
        $lot = InventoryLot::factory()->forItem($this->oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        app(InventoryLedgerService::class)->receive($this->oil, $this->depotFg, '3', $lot, InventoryTransactionType::StockAdjustmentIn);

        $this->office = User::factory()->create(['name' => 'Office']);
        $this->office->assignRole(RoleName::SuperAdmin->value);

        $this->orders = app(OnlineOrderService::class);
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'Hair oil 200 ml', $this->oil, 1, $this->office);
    }

    /**
     * @return array<string, mixed>
     */
    private function parcel(string $awb, array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return [
            'awb' => $awb, 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'Hair oil 200 ml', 'qty' => 1,
            'order' => sprintf('2000000000000%05d', $n), 'invoice' => sprintf('fghij%04d', $n), 'total' => '199.00',
            'name' => "Test Customer {$n}", 'state' => 'Uttar Pradesh',
            ...$overrides,
        ];
    }

    private function onHand(): string
    {
        return (string) app(StockBalanceService::class)->onHand($this->oil, $this->depotFg)->strippedOfTrailingZeros();
    }

    private function upload(array $parcels): void
    {
        $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [LabelFixtures::meesho($parcels)], $this->office);
    }

    #[Test]
    public function stock_leaves_when_the_labels_are_uploaded_and_what_is_short_waits(): void
    {
        $this->upload([
            $this->parcel('VL2000000000001'),
            $this->parcel('VL2000000000002', ['qty' => 2]),
            $this->parcel('VL2000000000003'),
        ]);

        $parcels = Shipment::query()->orderBy('id')->get();
        $this->assertSame([StockState::Consumed, StockState::Consumed, StockState::Short], $parcels->pluck('stock_state')->all());
        $this->assertSame([ShipmentStatus::Uploaded, ShipmentStatus::Uploaded, ShipmentStatus::Uploaded], $parcels->pluck('status')->all(), 'The labels are still to be printed.');
        $this->assertSame('0', $this->onHand());
        $this->assertSame('199.00', (string) $parcels[0]->lines->first()->unit_price, 'What one piece sold for.');
        $this->assertSame('99.50', (string) $parcels[1]->lines->first()->unit_price);

        // Stock arriving serves the parcel that was short, at once.
        $lot = InventoryLot::factory()->forItem($this->oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        app(InventoryLedgerService::class)->receive($this->oil, $this->depotFg, '5', $lot, InventoryTransactionType::StockAdjustmentIn);
        $this->orders->holdAgain($parcels[2]->batch);

        $this->assertSame(StockState::Consumed, $parcels[2]->fresh()->stock_state);
        $this->assertSame('4', $this->onHand());

        // A packing scan afterwards takes nothing more.
        $this->orders->pack($parcels[0]->fresh(), $this->office);
        $this->assertSame('4', $this->onHand());
        $this->assertSame(ShipmentStatus::Packed, $parcels[0]->fresh()->status);
    }

    #[Test]
    public function the_pick_list_says_what_to_take_off_the_racks_and_what_is_short(): void
    {
        $this->upload([
            $this->parcel('VL2000000000011'),
            $this->parcel('VL2000000000012', ['qty' => 2, 'total' => '380.00']),
            $this->parcel('VL2000000000013'),
        ]);

        $this->actingAs($this->office)->get(route('online-orders.pick-list'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('online-orders/pick-list')
                ->where('stores.0.pieces', 4)
                ->where('stores.0.short', 1)
                ->where('stores.0.products.0.name', 'Rahat Rooh Hair Oil 200 ml')
                ->where('stores.0.products.0.parcels', 3)
                ->where('stores.0.products.0.price_low', '190.00')
                ->where('stores.0.products.0.price_high', '199.00')
                ->where('stores.0.products.0.on_shelf', 0));
    }

    #[Test]
    public function a_cancelled_order_scanned_back_in_goes_back_on_the_shelf(): void
    {
        $this->upload([$this->parcel('VL2000000000021'), $this->parcel('VL2000000000022')]);
        $this->assertSame('1', $this->onHand());

        $this->actingAs($this->office)->postJson(route('online-orders.scan-back.store'), ['code' => 'VL2000000000021', 'mode' => 'cancelled'])
            ->assertOk()->assertJsonPath('flag', 'Cancelled')->assertJsonPath('goods.0.units', '1');

        $parcel = Shipment::query()->where('awb', 'VL2000000000021')->sole();
        $this->assertSame([ShipmentStatus::Cancelled, StockState::Released], [$parcel->status, $parcel->stock_state]);
        $this->assertSame('2', $this->onHand());

        // Scanned twice, it is not put back twice.
        $this->actingAs($this->office)->postJson(route('online-orders.scan-back.store'), ['code' => 'VL2000000000021', 'mode' => 'cancelled'])->assertStatus(422);
        $this->actingAs($this->office)->postJson(route('online-orders.scan-back.store'), ['code' => 'NOPE', 'mode' => 'cancelled'])->assertNotFound();
        $this->assertSame('2', $this->onHand());
    }

    #[Test]
    public function a_return_scanned_back_in_is_received_whole_and_flagged_returned(): void
    {
        $this->upload([$this->parcel('VL2000000000031', ['qty' => 2])]);
        $this->assertSame('1', $this->onHand());

        // Never scanned at packing: its stock left at upload all the same.
        $this->actingAs($this->office)->postJson(route('online-orders.scan-back.store'), ['code' => 'VL2000000000031', 'mode' => 'return'])
            ->assertOk()->assertJsonPath('goods.0.units', '2');

        $parcel = Shipment::query()->where('awb', 'VL2000000000031')->sole();
        $this->assertSame([ShipmentStatus::Returned, StockState::Returned], [$parcel->status, $parcel->stock_state]);
        $this->assertNotNull($parcel->shipmentReturn);
        $this->assertSame('3', $this->onHand());
    }

    #[Test]
    public function removing_a_wrong_file_puts_its_stock_back(): void
    {
        $this->upload([$this->parcel('VL2000000000041'), $this->parcel('VL2000000000042')]);
        $this->assertSame('1', $this->onHand());

        $this->orders->removeFile(Shipment::query()->firstOrFail()->file);

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame('3', $this->onHand());
    }

    #[Test]
    public function a_depot_that_scans_at_packing_still_only_holds_the_stock(): void
    {
        $this->depotFg->facility->forceFill(['takes_stock_on_upload' => false])->save();

        $this->upload([$this->parcel('VL2000000000051')]);

        $this->assertSame(StockState::Reserved, Shipment::query()->sole()->stock_state);
        $this->assertSame('3', $this->onHand());
    }
}
