<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Services\ProductPackService;
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
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeLabelReader;
use Tests\Support\LabelFixtures;
use Tests\TestCase;

/**
 * Stock → the depot's finished goods store → Scan in stock. Each scan is a
 * piece (or a pack of two) of a product; the depot gives the day it came
 * in and, when known, its manufacturing and expiry dates; booking puts it
 * on the shelf dated that day. Pack of 2 is its own product.
 */
class ScanInStockTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $depotFg;

    private Product $oil;

    private User $shanu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('files');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));
        config(['erp.company.timezone' => 'Asia/Kolkata']);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00', 'Asia/Kolkata'));

        $depot = Facility::factory()->withStores([WarehouseType::FinishedGoods, WarehouseType::RawMaterial])
            ->create(['name' => 'Paper Market Facility', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->depotFg = $depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->seed(ReferenceDataSeeder::class);

        $this->oil = Product::factory()->create([
            'code' => 'RR-MED-500', 'name' => 'Rahat Rooh Medicated Oil 500ml', 'barcode' => '8901234500011',
            'shelf_life_days' => 730, 'stock_uom_id' => Uom::where('code', 'PCS')->sole()->id, 'client_id' => null,
        ]);

        $this->shanu = User::factory()->create(['name' => 'Shanu Kumar']);
        $this->shanu->assignRole(RoleName::WarehouseManager->value);
        app(EmployeeAssignmentService::class)->assign($this->shanu, $depot, null, ['is_primary' => true], null);
    }

    private function onHand(Item $item): string
    {
        return (string) app(StockBalanceService::class)->onHand($item, $this->depotFg)->strippedOfTrailingZeros();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function book(array $lines, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->shanu)->from(route('stock.index'))->post(route('stock.scan-in.book', $this->depotFg), ['lines' => $lines]);
    }

    #[Test]
    public function the_finished_goods_store_offers_scan_in_with_single_products_only(): void
    {
        app(ProductPackService::class)->packOf($this->oil, 2, $this->shanu->id);

        $this->actingAs($this->shanu)->get(route('stock.index', ['warehouse' => $this->depotFg->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.scan_in', true)
                ->where('scan_products', [['value' => (string) $this->oil->id, 'label' => 'Rahat Rooh Medicated Oil 500ml', 'hint' => 'RR-MED-500', 'shelf_life_days' => 730]]));

        $raw = $this->depotFg->facility->stores()->where('type', WarehouseType::RawMaterial->value)->sole();
        $this->actingAs($this->shanu)->get(route('stock.index', ['warehouse' => $raw->id]))
            ->assertInertia(fn (Assert $page) => $page->where('can.scan_in', false)->where('scan_products', []));
    }

    #[Test]
    public function a_barcode_finds_its_product_and_an_unknown_one_says_so(): void
    {
        $this->actingAs($this->shanu)->getJson(route('stock.scan-in.lookup', ['code' => '8901234500011']))->assertOk()
            ->assertJson(['product_id' => $this->oil->id, 'pack' => null, 'code' => 'RR-MED-500']);

        $this->actingAs($this->shanu)->getJson(route('stock.scan-in.lookup', ['code' => '0000000000000']))
            ->assertNotFound()->assertJsonFragment(['message' => 'Barcode 0000000000000 is not on any product yet. Choose the product and press Add: the barcode is kept for next time.']);
    }

    #[Test]
    public function scanned_stock_is_booked_on_the_day_it_came_in_with_its_dates(): void
    {
        $this->book([
            ['product_id' => $this->oil->id, 'pack' => 1, 'quantity' => 24, 'came_in' => '2026-10-03', 'manufactured_at' => '2026-09-15'],
        ])->assertRedirect(route('stock.index'));

        $this->assertStringContainsString('24 piece(s) booked into', Inertia::getFlashed()['toast']['message']);
        $this->assertSame('24', $this->onHand($this->oil));

        $lot = InventoryLot::query()->where('item_id', $this->oil->id)->sole();
        $this->assertSame(['2026-09-15', '2026-10-03', '2028-09-14'], [$lot->manufactured_at->toDateString(), $lot->received_at->toDateString(), $lot->expiry_at->toDateString()]);

        $posting = InventoryTransaction::query()->where('type', InventoryTransactionType::ScanReceipt->value)->sole();
        $this->assertSame('2026-10-03', $posting->transacted_at->timezone('Asia/Kolkata')->toDateString());
        $this->assertSame($this->shanu->id, $posting->created_by);
    }

    #[Test]
    public function pack_of_2_is_its_own_product_added_the_first_time(): void
    {
        $this->book([['product_id' => $this->oil->id, 'pack' => 2, 'quantity' => 10, 'came_in' => '2026-10-25']]);
        $this->assertStringContainsString('New product: Rahat Rooh Medicated Oil 500ml (Pack of 2) (RR-MED-500-P2)', Inertia::getFlashed()['toast']['message']);

        $pack = Product::query()->where('code', 'RR-MED-500-P2')->sole();
        $this->assertSame([$this->oil->id, 2], [$pack->pack_of_item_id, $pack->pack_quantity]);
        $this->assertSame('10', $this->onHand($pack));
        $this->assertSame('0', $this->onHand($this->oil));

        // The second time, the same pack product.
        $this->book([['product_id' => $this->oil->id, 'pack' => 2, 'quantity' => 5, 'came_in' => '2026-10-25']]);
        $this->assertSame(1, Product::query()->where('pack_of_item_id', $this->oil->id)->count());
        $this->assertSame('15', $this->onHand($pack));
        $this->assertStringNotContainsString('New product', Inertia::getFlashed()['toast']['message']);
    }

    #[Test]
    public function a_barcode_met_for_the_first_time_is_kept_on_the_product(): void
    {
        $lauki = Product::factory()->create(['code' => 'RR-LK-500', 'name' => 'Rahat Rooh Lauki Oil 500ml', 'barcode' => null, 'stock_uom_id' => $this->oil->stock_uom_id]);

        $this->book([['product_id' => $lauki->id, 'pack' => 1, 'quantity' => 1, 'came_in' => '2026-10-25', 'barcode' => '8909999000017']]);

        $this->assertSame('8909999000017', $lauki->refresh()->barcode);
        $this->actingAs($this->shanu)->getJson(route('stock.scan-in.lookup', ['code' => '8909999000017']))->assertJson(['product_id' => $lauki->id]);

        // A barcode already on another product stays where it is.
        $this->book([['product_id' => $lauki->id, 'pack' => 1, 'quantity' => 1, 'came_in' => '2026-10-25', 'barcode' => '8901234500011']]);
        $this->assertSame('8901234500011', $this->oil->refresh()->barcode);
        $this->assertSame('8909999000017', $lauki->refresh()->barcode);
    }

    #[Test]
    public function a_day_later_than_today_or_made_after_it_came_in_is_refused(): void
    {
        $this->book([['product_id' => $this->oil->id, 'pack' => 1, 'quantity' => 1, 'came_in' => '2026-10-26']]);
        $this->assertStringContainsString('cannot be blank or later than today', Inertia::getFlashed()['toast']['message']);

        $this->book([['product_id' => $this->oil->id, 'pack' => 1, 'quantity' => 1, 'came_in' => '2026-10-02', 'manufactured_at' => '2026-10-05']]);
        $this->assertStringContainsString('cannot be made after the day it came in', Inertia::getFlashed()['toast']['message']);

        $this->assertSame('0', $this->onHand($this->oil));
    }

    #[Test]
    public function parcels_short_of_the_product_get_their_stock_when_it_is_scanned_in(): void
    {
        $agency = User::factory()->create();
        $agency->assignRole(RoleName::EcommerceAgency->value);
        $brand = Brand::query()->where('code', 'RR')->sole();
        $meesho = Marketplace::query()->where('code', 'MEESHO')->sole();
        $orders = app(OnlineOrderService::class);
        $orders->mapSku($meesho, $brand, 'Medicated oil 500 ml', $this->oil, 1, $agency);
        $orders->upload($brand, $meesho, $this->depotFg, [LabelFixtures::meesho([[
            'awb' => 'VL1000000000001', 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'Medicated oil 500 ml', 'qty' => 1,
            'order' => '200000000000000001', 'invoice' => 'abcde0001', 'total' => '399.00', 'name' => 'Test Customer', 'state' => 'Bihar',
        ]])], $agency);
        $this->assertSame(StockState::Short, Shipment::query()->sole()->stock_state);

        $this->book([['product_id' => $this->oil->id, 'pack' => 1, 'quantity' => 3, 'came_in' => '2026-10-25']]);

        $this->assertStringContainsString('1 waiting parcel(s) now have their stock', Inertia::getFlashed()['toast']['message']);
        $this->assertSame(StockState::Reserved, Shipment::query()->sole()->stock_state);
    }

    #[Test]
    public function only_the_depot_scans_stock_in(): void
    {
        $elsewhere = User::factory()->create();
        $elsewhere->assignRole(RoleName::WarehouseManager->value);
        app(EmployeeAssignmentService::class)->assign($elsewhere, Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(), null, ['is_primary' => true], null);
        $this->book([['product_id' => $this->oil->id, 'pack' => 1, 'quantity' => 1, 'came_in' => '2026-10-25']], $elsewhere)->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->assignRole(RoleName::Viewer->value);
        $this->book([['product_id' => $this->oil->id, 'pack' => 1, 'quantity' => 1, 'came_in' => '2026-10-25']], $viewer)->assertForbidden();

        $this->assertSame('0', $this->onHand($this->oil));
    }

    #[Test]
    public function pack_of_2_listings_are_switched_to_the_pack_product_on_sku_mapping(): void
    {
        $office = User::factory()->create();
        $office->assignRole(RoleName::SuperAdmin->value);
        $brand = Brand::query()->where('code', 'RR')->sole();
        $meesho = Marketplace::query()->where('code', 'MEESHO')->sole();
        $orders = app(OnlineOrderService::class);
        $orders->mapSku($meesho, $brand, 'Medicated oil 500 ml pack of 2', $this->oil, 2, $office);
        $orders->upload($brand, $meesho, $this->depotFg, [LabelFixtures::meesho([[
            'awb' => 'VL1000000000002', 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'Medicated oil 500 ml pack of 2', 'qty' => 1,
            'order' => '200000000000000002', 'invoice' => 'abcde0002', 'total' => '699.00', 'name' => 'Test Customer', 'state' => 'Bihar',
        ]])], $office);

        $this->actingAs($office)->get(route('listings.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('pack_moves', 1)->where('pack_moves.0.item_code', 'RR-MED-500')->where('pack_moves.0.quantity', 2)->where('pack_moves.0.pack', null));

        $this->actingAs($office)->from(route('listings.index'))->post(route('listings.packs'), ['item_id' => $this->oil->id, 'quantity' => 2])
            ->assertRedirect(route('listings.index'));
        $this->assertStringContainsString('1 listing(s) now take Rahat Rooh Medicated Oil 500ml (Pack of 2)', Inertia::getFlashed()['toast']['message']);

        $pack = Product::query()->where('pack_of_item_id', $this->oil->id)->sole();
        $listing = MarketplaceListing::query()->sole();
        $this->assertSame([$pack->id, 1], [$listing->item_id, $listing->units_per_order]);

        // The waiting parcel now takes one pack, and gets it once packs are scanned in.
        $this->book([['product_id' => $this->oil->id, 'pack' => 2, 'quantity' => 4, 'came_in' => '2026-10-25']], $office);
        $parcel = Shipment::query()->with('picks')->sole();
        $this->assertSame([$pack->id], $parcel->picks->pluck('item_id')->all());
        $this->assertSame(StockState::Reserved, $parcel->stock_state);

        $this->actingAs($office)->get(route('listings.index'))->assertInertia(fn (Assert $page) => $page->has('pack_moves', 0));
    }
}
