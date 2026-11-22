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
use App\Domain\Marketplace\Models\MarketplaceListing;
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
 * The packing check: the label is scanned first and the screen shows, piece
 * by piece, what goes in the parcel and the barcodes that prove it. Nothing
 * is packed until the products are proved; a Pack of 2 is its single twice.
 */
class PackCheckFlowTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $depotFg;

    private Product $oil;

    private Product $pack;

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
            ['awb' => 'DL0000000000004', 'courier' => 'Delhivery', 'payment' => 'cod', 'sku' => 'Medicated oil 300 ml 2pack', 'qty' => 1, 'order' => '200000000000004', 'invoice' => 'abcde0004', 'total' => '380.00', 'name' => 'C 4', 'state' => 'Delhi'],
            ['awb' => 'DL0000000000005', 'courier' => 'Delhivery', 'payment' => 'cod', 'sku' => 'Mystery oil', 'qty' => 1, 'order' => '200000000000005', 'invoice' => 'abcde0005', 'total' => '99.00', 'name' => 'C 5', 'state' => 'Delhi'],
        ])], $agency);
        $this->oil->forceFill(['barcode' => '8901234567890'])->save();
        $this->pack = Product::factory()->create(['code' => 'FG-MO-300-P2', 'name' => 'Rahat Rooh Medicated Oil 300ml (Pack of 2)', 'stock_uom_id' => $this->oil->stock_uom_id, 'client_id' => null, 'pack_of_item_id' => $this->oil->id, 'pack_quantity' => 2]);
        $packLot = InventoryLot::factory()->forItem($this->pack)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        app(InventoryLedgerService::class)->receive($this->pack, $this->depotFg, '5', $packLot, InventoryTransactionType::StockAdjustmentIn);
        $orders->mapSku($meesho, $brand, 'Medicated oil 300 ml', $this->oil, 1, $agency);
        $orders->mapSku($meesho, $brand, 'Medicated oil 300 ml 2pack', $this->pack, 1, $agency);
    }

    private function open(string $code)
    {
        return $this->actingAs($this->shanu)->postJson(route('floor.pack.open'), ['code' => $code]);
    }

    private function parcel(string $awb): Shipment
    {
        return Shipment::query()->where('awb', $awb)->sole();
    }

    #[Test]
    public function the_label_scan_shows_what_goes_in_and_packs_nothing_yet(): void
    {
        $shown = $this->open('VL0000000000001')->assertOk()->json();

        $this->assertSame('open', $shown['result']);
        $this->assertTrue($this->parcel('VL0000000000001')->status->awaitsPacking());
        $this->assertCount(1, $shown['shipment']['check']);

        $row = $shown['shipment']['check'][0];
        $this->assertSame($this->oil->id, $row['item_id']);
        $this->assertSame('Rahat Rooh Medicated Oil', $row['name']);
        $this->assertSame('300 ml', $row['size']);
        $this->assertSame(1, $row['pieces']);
        $this->assertTrue($row['has_barcode']);
        $this->assertSame(1, $row['codes']['8901234567890']);
        $this->assertSame(1, $row['codes']['FG-MO-300']);
        $this->assertSame('/images/products/medicated.png', $row['photo']);
        $this->assertSame('Meesho', $shown['shipment']['marketplace']);

        // Every product proved: the same label code now packs it.
        $packed = $this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => 'VL0000000000001'])->assertOk()->json();
        $this->assertSame('scanned', $packed['result']);
        $this->assertSame(ShipmentStatus::Packed, $this->parcel('VL0000000000001')->status);
        $this->assertNull($this->parcel('VL0000000000001')->pack_note);

        // Scanned again, the label is answered as before: already scanned.
        $this->assertSame('already', $this->open('VL0000000000001')->assertOk()->json('result'));
    }

    #[Test]
    public function a_pack_of_two_is_its_single_product_twice(): void
    {
        $row = $this->open('DL0000000000004')->assertOk()->json('shipment.check.0');

        $this->assertSame($this->oil->id, $row['item_id']);
        $this->assertSame('Rahat Rooh Medicated Oil', $row['name']);
        $this->assertSame(2, $row['pieces']);
        // The single's barcode is one piece; the pack's own code is both.
        $this->assertSame(1, $row['codes']['8901234567890']);
        $this->assertSame(2, $row['codes']['FG-MO-300-P2']);
    }

    #[Test]
    public function a_product_passed_by_eye_is_on_record(): void
    {
        $this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => 'VL0000000000002', 'by_eye' => ['Rahat Rooh Medicated Oil 300ml']])->assertOk();

        $this->assertSame('Checked by eye, not by barcode: Rahat Rooh Medicated Oil 300ml', $this->parcel('VL0000000000002')->pack_note);
    }

    #[Test]
    public function a_label_whose_sku_is_not_mapped_is_stopped_at_the_label(): void
    {
        $stopped = $this->open('DL0000000000005')->assertStatus(422)->json();

        $this->assertFalse($stopped['ok']);
        $this->assertStringContainsString('Mystery oil', $stopped['message']);
        $this->assertTrue($this->parcel('DL0000000000005')->status->awaitsPacking());
    }

    #[Test]
    public function a_wrong_product_is_named_by_its_barcode(): void
    {
        $this->actingAs($this->shanu)->getJson(route('floor.pack.product', ['code' => '8901234567890']))
            ->assertOk()
            ->assertJson(['item_id' => $this->oil->id, 'name' => 'Rahat Rooh Medicated Oil 300ml']);

        $this->actingAs($this->shanu)->getJson(route('floor.pack.product', ['code' => '0000000000000']))->assertNotFound();
    }

    #[Test]
    public function a_product_with_no_barcode_is_flagged_on_the_packing_screen_and_the_product_list(): void
    {
        // With its barcode on file nothing is flagged.
        $this->actingAs($this->shanu)->get(route('floor.pack'))
            ->assertInertia(fn (Assert $page) => $page->where('no_barcode', []));

        Product::query()->update(['barcode' => 'X']);
        $this->oil->forceFill(['barcode' => null])->save();

        // Named once, though it waits in single parcels and in a Pack of 2.
        $this->actingAs($this->shanu)->get(route('floor.pack'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('no_barcode', [['id' => $this->oil->id, 'name' => 'Rahat Rooh Medicated Oil 300ml']]));

        $this->actingAs($this->boss)->get(route('products.index'))
            ->assertInertia(fn (Assert $page) => $page->where('no_barcode', 1));

        // The pack has no barcode of its own either, but its single proves it.
        $this->pack->forceFill(['barcode' => null])->save();

        $this->actingAs($this->boss)->get(route('products.index', ['barcode' => 'missing']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('no_barcode', 1)
                ->has('items.data', 1)
                ->where('items.data.0.id', $this->oil->id));
    }

    #[Test]
    public function the_online_orders_page_lists_the_days_products_each_as_its_single(): void
    {
        $this->actingAs($this->boss)->get(route('online-orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('online-orders/index')
                // Three single parcels and a Pack of 2: five pieces in four orders.
                ->has('products', 1)
                ->where('products.0.item_id', $this->oil->id)
                ->where('products.0.name', 'Rahat Rooh Medicated Oil')
                ->where('products.0.size', '300 ml')
                ->where('products.0.pieces', 5)
                ->where('products.0.to_pack', 5)
                ->where('products.0.orders', 4)
                ->where('products.0.has_barcode', true)
                ->where('products.0.photo', '/images/products/medicated.png'));

        // One packed: it is still in the day's count, no longer to pack.
        $this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => 'DL0000000000004'])->assertOk();

        $this->actingAs($this->boss)->get(route('online-orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.0.pieces', 5)
                ->where('products.0.to_pack', 3));
    }

    #[Test]
    public function an_order_quantity_typed_in_as_pieces_is_flagged_and_put_right_with_its_stock(): void
    {
        $orders = app(OnlineOrderService::class);
        $brand = Brand::query()->where('code', 'RR')->sole();
        $meesho = Marketplace::query()->where('code', 'MEESHO')->sole();
        $onShelf = fn () => app(StockBalanceService::class)->onHand($this->oil, $this->depotFg)->toInt();
        $pieces = fn (string $awb) => (int) $this->parcel($awb)->picks()->sum('units');
        $suspects = fn () => collect($this->actingAs($this->boss)->get(route('listings.index'))->viewData('page')['props']['listings'])
            ->where('suspect', true)->pluck('seller_sku')->all();

        // The depot takes stock as labels are matched, as Paper Market does.
        $this->depotFg->facility->forceFill(['takes_stock_on_upload' => true])->save();
        $this->assertSame([], $suspects());

        // Someone saw an order for four and typed 4 pieces: every single
        // parcel now asks for four, and four leave the shelf for each.
        $orders->mapSku($meesho, $brand, 'Medicated oil 300 ml', $this->oil, 4, $this->boss);
        $this->assertSame(4, $pieces('VL0000000000001'));
        $this->assertSame(20 - 12, $onShelf());
        $this->assertSame(['Medicated oil 300 ml'], $suspects(), 'A real Pack of 2 is not flagged.');

        // Set back to one piece: the parcels ask for one and the extra stock is back.
        $orders->mapSku($meesho, $brand, 'Medicated oil 300 ml', $this->oil, 1, $this->boss);
        $this->assertSame(1, $pieces('VL0000000000001'));
        $this->assertSame(20 - 3, $onShelf());
        $this->assertSame([], $suspects());
    }

    #[Test]
    public function a_mapping_can_be_deleted_and_its_waiting_parcels_go_back_to_be_mapped(): void
    {
        $listing = MarketplaceListing::query()->where('seller_sku', 'Medicated oil 300 ml')->sole();
        $onShelf = fn () => app(StockBalanceService::class)->onHand($this->oil, $this->depotFg)->toInt();

        // One is packed before the mapping goes: it keeps what it was packed as.
        $this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => 'VL0000000000001'])->assertOk();
        $this->assertSame(19, $onShelf());

        $this->actingAs($this->boss)->delete(route('listings.destroy', $listing))->assertRedirect();

        $this->assertNull(MarketplaceListing::query()->find($listing->id));
        $this->assertSame(ShipmentStatus::Packed, $this->parcel('VL0000000000001')->status);
        $this->assertSame(1, (int) $this->parcel('VL0000000000001')->picks()->sum('units'));
        $this->assertSame(StockState::Unmapped, $this->parcel('VL0000000000002')->stock_state);
        $this->assertSame(0, $this->parcel('VL0000000000002')->picks()->count());
        $this->assertSame(19, $onShelf());

        // The SKU is waiting to be mapped again, and can be.
        $this->actingAs($this->boss)->get(route('listings.index'))
            ->assertInertia(fn (Assert $page) => $page->where('waiting.0.seller_sku', 'Medicated oil 300 ml')->etc());
    }
}
