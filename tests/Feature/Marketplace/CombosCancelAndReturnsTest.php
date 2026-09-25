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
use App\Domain\Marketplace\Enums\ClaimStatus;
use App\Domain\Marketplace\Enums\ReturnKind;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Models\ShipmentReturn;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\Marketplace\Services\ReturnService;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\EmployeeAssignmentService;
use App\Http\Controllers\OnlineOrders\OnlineOrderPresenter;
use App\Models\User;
use Brick\Math\BigDecimal;
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
 * The second part of online orders: combos and packs of two picked
 * product by product; the main screen's filters; cancelling an order from
 * anywhere; and parcels that come back — good goods to the shelf in the
 * batch they left from, damaged goods to the damaged store, a claim when
 * one is owed.
 */
class CombosCancelAndReturnsTest extends TestCase
{
    use RefreshDatabase;

    private Facility $depot;

    private Warehouse $depotFg;

    private Product $oil;

    private Product $satreetha;

    private InventoryLot $oilLot;

    private InventoryLot $satreethaLot;

    private Brand $rahatRooh;

    private Marketplace $meesho;

    private User $agency;

    private User $packer;

    private User $dispatcher;

    private OnlineOrderService $orders;

    private ReturnService $returns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        config(['erp.company.brand' => 'Rahat Rooh', 'erp.online_orders.cutoff' => '16:00', 'erp.company.timezone' => 'Asia/Kolkata']);
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));

        $this->depot = Facility::factory()
            ->withStores([WarehouseType::FinishedGoods])
            ->create(['name' => 'Paper Market Depot', 'gstin' => '07AAAAA0000A1Z5', 'can_dispatch' => true]);
        $this->depotFg = $this->depot->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();
        $this->seed(ReferenceDataSeeder::class);

        $this->rahatRooh = Brand::query()->where('code', 'RR')->sole();
        $this->meesho = Marketplace::query()->where('code', 'MEESHO')->sole();
        $this->meesho->update(['claim_window_hours' => 48]);

        $pcs = Uom::where('code', 'PCS')->sole();
        $this->oil = Product::factory()->create(['name' => 'Rahat Rooh 500 ml', 'stock_uom_id' => $pcs->id, 'client_id' => null]);
        $this->satreetha = Product::factory()->create(['name' => 'Satreetha Soap', 'stock_uom_id' => $pcs->id, 'client_id' => null]);

        $ledger = app(InventoryLedgerService::class);
        $this->oilLot = InventoryLot::factory()->forItem($this->oil)->create(['batch_number' => 'RR-0901', 'qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        $this->satreethaLot = InventoryLot::factory()->forItem($this->satreetha)->create(['batch_number' => 'ST-0901', 'qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        $ledger->receive($this->oil, $this->depotFg, '20', $this->oilLot, InventoryTransactionType::StockAdjustmentIn);
        $ledger->receive($this->satreetha, $this->depotFg, '10', $this->satreethaLot, InventoryTransactionType::StockAdjustmentIn);

        $this->agency = User::factory()->create(['name' => 'Agency Desk']);
        $this->agency->assignRole(RoleName::EcommerceAgency->value);
        $this->agency->brands()->attach($this->rahatRooh);

        $this->packer = User::factory()->create(['name' => 'Packing Table']);
        $this->packer->assignRole(RoleName::StoreExecutive->value);
        app(EmployeeAssignmentService::class)->assign($this->packer, $this->depot, null, ['is_primary' => true], null);

        $this->dispatcher = User::factory()->create(['name' => 'Dispatch Desk']);
        $this->dispatcher->assignRole(RoleName::DispatchManager->value);

        $this->orders = app(OnlineOrderService::class);
        $this->returns = app(ReturnService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function label(string $awb, array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return [
            'awb' => $awb, 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'RR 500 ml', 'qty' => 1,
            'order' => sprintf('3000000000000%05d', $n), 'invoice' => sprintf('klmno%04d', $n), 'total' => '399.00',
            'name' => "Test Customer {$n}", 'state' => 'Delhi', ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $labels
     */
    private function upload(array $labels, string $name = 'labels.pdf'): void
    {
        $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [LabelFixtures::meesho($labels, $name)], $this->agency);
    }

    private function free(Product $product, ?Warehouse $store = null): string
    {
        return (string) app(StockBalanceService::class)->available($product, $store ?? $this->depotFg)->strippedOfTrailingZeros();
    }

    private function onHand(Product $product, Warehouse $store, ?InventoryLot $lot = null): string
    {
        return (string) app(StockBalanceService::class)->onHand($product, $store, $lot)->strippedOfTrailingZeros();
    }

    private function packAndHandOver(string $awb): Shipment
    {
        $shipment = $this->orders->pack($this->orders->findByCode($awb), $this->packer);
        $this->orders->handOver($this->depotFg, 'Valmo', [$shipment->id], $this->packer);

        return $shipment->refresh();
    }

    // ---- Combos and packs of two ------------------------------------------

    #[Test]
    public function a_combo_listing_holds_and_packs_every_product_in_it(): void
    {
        $listing = $this->orders->mapSkuTo($this->meesho, $this->rahatRooh, 'RR500 + Satreetha', [
            ['item' => $this->oil, 'units' => 1],
            ['item' => $this->satreetha, 'units' => 1],
        ], $this->agency);

        $this->assertTrue($listing->isCombo());
        $this->assertSame(2, $listing->components()->count());

        $this->upload([$this->label('VL1000000000001', ['sku' => 'RR500 + Satreetha', 'qty' => 2])]);

        $shipment = Shipment::query()->sole();
        $this->assertSame(StockState::Reserved, $shipment->stock_state);
        $this->assertTrue($shipment->isMapped());
        $this->assertSame('18', $this->free($this->oil));
        $this->assertSame('8', $this->free($this->satreetha));

        // The screens get one pick per product, with the pieces.
        $picks = OnlineOrderPresenter::picks($shipment->refresh());
        $this->assertSame([['Rahat Rooh 500 ml', '2'], ['Satreetha Soap', '2']], array_map(fn ($p) => [$p['item'], $p['units']], $picks));
        // A combo line names no single product.
        $this->assertNull($shipment->lines()->sole()->item_id);

        $this->orders->pack($shipment, $this->packer);

        $posting = InventoryTransaction::query()->where('type', InventoryTransactionType::MarketplaceSale->value)->with('lines')->sole();
        $this->assertEqualsCanonicalizing([$this->oil->id, $this->satreetha->id], $posting->lines->pluck('item_id')->all());
        $this->assertSame('18', $this->onHand($this->oil, $this->depotFg));
        $this->assertSame('8', $this->onHand($this->satreetha, $this->depotFg));
    }

    #[Test]
    public function two_different_products_on_one_label_are_both_picked(): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'RR 500 ml', $this->oil, 1, $this->agency);
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'Satreetha soap', $this->satreetha, 1, $this->agency);
        $this->upload([$this->label('VL1000000000002')]);

        // The label read one row; the office adds the second product.
        $shipment = $this->orders->correct(Shipment::query()->sole(), ['lines' => [
            ['seller_sku' => 'RR 500 ml', 'quantity' => 1],
            ['seller_sku' => 'Satreetha soap', 'quantity' => 1],
        ]]);

        $this->assertSame(StockState::Reserved, $shipment->stock_state);
        $this->assertCount(2, OnlineOrderPresenter::picks($shipment->refresh()));

        $this->orders->pack($shipment, $this->packer);
        $this->assertSame('19', $this->onHand($this->oil, $this->depotFg));
        $this->assertSame('9', $this->onHand($this->satreetha, $this->depotFg));
    }

    #[Test]
    public function a_pack_of_two_is_one_product_picked_twice(): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'RR 500 ml PO2', $this->oil, 2, $this->agency);
        $this->upload([$this->label('VL1000000000003', ['sku' => 'RR 500 ml PO2'])]);

        $picks = OnlineOrderPresenter::picks(Shipment::query()->sole());
        $this->assertCount(1, $picks);
        $this->assertSame('2', $picks[0]['units']);
        $this->assertSame('18', $this->free($this->oil));
    }

    #[Test]
    public function packing_a_combo_short_of_one_product_names_that_product(): void
    {
        $empty = Product::factory()->create(['name' => 'Kesh Oil 100 ml', 'stock_uom_id' => $this->oil->stock_uom_id, 'client_id' => null]);
        $this->orders->mapSkuTo($this->meesho, $this->rahatRooh, 'RR + Kesh', [
            ['item' => $this->oil, 'units' => 1],
            ['item' => $empty, 'units' => 1],
        ], $this->agency);
        $this->upload([$this->label('VL1000000000004', ['sku' => 'RR + Kesh'])]);

        $shipment = Shipment::query()->sole();
        $this->assertSame(StockState::Short, $shipment->stock_state);

        try {
            $this->orders->pack($shipment, $this->packer);
            $this->fail('A parcel short of one product must not be packed.');
        } catch (OnlineOrderException $e) {
            $this->assertStringContainsString('Kesh Oil 100 ml', $e->getMessage());
            $this->assertStringNotContainsString('Rahat Rooh 500 ml', $e->getMessage());
        }

        // Nothing of the combo was held while one product was short.
        $this->assertSame('20', $this->free($this->oil));
    }

    #[Test]
    public function the_sku_mapping_screen_saves_a_combo(): void
    {
        $this->upload([$this->label('VL1000000000005', ['sku' => 'Gift set'])]);

        $this->actingAs($this->dispatcher)->post(route('listings.store'), [
            'marketplace_id' => $this->meesho->id,
            'brand_id' => $this->rahatRooh->id,
            'seller_sku' => 'Gift set',
            'components' => [
                ['item_id' => $this->oil->id, 'units' => 2],
                ['item_id' => $this->satreetha->id, 'units' => 1],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $listing = MarketplaceListing::query()->where('seller_sku', 'Gift set')->sole();
        $this->assertSame([[$this->oil->id, 2], [$this->satreetha->id, 1]], $listing->components->map(fn ($c) => [$c->item_id, $c->units_per_order])->all());
        $this->assertSame('18', $this->free($this->oil));
        $this->assertSame('9', $this->free($this->satreetha));

        $this->actingAs($this->dispatcher)->get(route('listings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('listings.0.components', 2));
    }

    // ---- Main screen ------------------------------------------------------

    #[Test]
    public function the_main_screen_lists_parcels_by_the_tile_and_courier_clicked(): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'RR 500 ml', $this->oil, 1, $this->agency);
        $this->upload([
            $this->label('VL1000000000011'),
            $this->label('VL1000000000012'),
            $this->label('SF1000000011FPL', ['courier' => 'Shadowfax']),
        ]);
        $this->orders->pack($this->orders->findByCode('VL1000000000011'), $this->packer);

        $this->actingAs($this->dispatcher)->get(route('online-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('show', 'all')
                ->has('parcels', 3)
                ->where('totals.packed', 1)
                ->has('parcels.0.picks', 1));

        $this->actingAs($this->dispatcher)->get(route('online-orders.index', ['show' => 'packed']))
            ->assertInertia(fn (Assert $page) => $page->where('show', 'packed')->has('parcels', 1)->where('parcels.0.awb', 'VL1000000000011'));

        $this->actingAs($this->dispatcher)->get(route('online-orders.index', ['show' => 'to_pack', 'courier' => 'Shadowfax']))
            ->assertInertia(fn (Assert $page) => $page->has('parcels', 1)->where('parcels.0.courier', 'Shadowfax'));

        // An unknown filter shows everything rather than failing.
        $this->actingAs($this->dispatcher)->get(route('online-orders.index', ['show' => 'nonsense']))
            ->assertInertia(fn (Assert $page) => $page->where('show', 'all')->has('parcels', 3));
    }

    // ---- Cancel from anywhere ---------------------------------------------

    #[Test]
    public function an_order_is_found_by_its_code_and_cancelled_by_the_depot_until_the_courier_has_it(): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'RR 500 ml', $this->oil, 1, $this->agency);
        $this->upload([$this->label('VL1000000000021'), $this->label('VL1000000000022')]);
        $order = Shipment::query()->where('awb', 'VL1000000000021')->sole()->order_number;

        // Found by its order number as well as its AWB.
        $this->actingAs($this->packer)->getJson(route('online-orders.lookup', ['code' => $order]))
            ->assertOk()
            ->assertJsonPath('shipment.awb', 'VL1000000000021')
            ->assertJsonPath('can_cancel', true);
        $this->actingAs($this->packer)->getJson(route('online-orders.lookup', ['code' => 'NOPE123']))->assertNotFound();

        // The packing table cancels a packed parcel: the goods go back.
        $packed = $this->orders->pack($this->orders->findByCode('VL1000000000021'), $this->packer);
        $this->assertSame('19', $this->onHand($this->oil, $this->depotFg));

        // The agency cannot cancel it once packed.
        $this->actingAs($this->agency)->post(route('online-orders.parcels.cancel', $packed), ['reason' => 'Cancelled by the customer'])->assertForbidden();

        $this->actingAs($this->packer)->post(route('online-orders.parcels.cancel', $packed), ['reason' => 'Cancelled by the customer'])->assertRedirect();
        $this->assertSame(ShipmentStatus::Cancelled, $packed->refresh()->status);
        $this->assertSame('20', $this->onHand($this->oil, $this->depotFg));

        // Once the courier has a parcel it is no longer the depot's to cancel.
        $other = $this->packAndHandOver('VL1000000000022');
        $this->actingAs($this->packer)->getJson(route('online-orders.lookup', ['code' => 'VL1000000000022']))
            ->assertJsonPath('can_cancel', false);
        $this->actingAs($this->dispatcher)->post(route('online-orders.parcels.cancel', $other), ['reason' => 'Late'])->assertForbidden();
    }

    // ---- Returns ----------------------------------------------------------

    #[Test]
    public function a_return_puts_good_goods_back_in_their_batch_and_damaged_goods_in_the_damaged_store(): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'RR 500 ml', $this->oil, 1, $this->agency);
        $this->upload([$this->label('VL1000000000031', ['qty' => 3])]);
        $shipment = $this->packAndHandOver('VL1000000000031');
        $this->assertSame('17', $this->onHand($this->oil, $this->depotFg));

        $sent = $this->returns->sent($shipment);
        $this->assertSame('3', (string) $sent[$this->oil->id]['units']->strippedOfTrailingZeros());

        $return = $this->returns->receive($shipment, ReturnKind::Rto, [$this->oil->id => ['good' => 2, 'damaged' => 1]], $this->packer, notes: 'One bottle leaking');

        $this->assertMatchesRegularExpression('/^RT-\d{4}-00001$/', $return->number);
        $line = $return->lines()->sole();
        $this->assertSame(['2', '1', '0'], [
            (string) BigDecimal::of($line->good)->strippedOfTrailingZeros(),
            (string) BigDecimal::of($line->damaged)->strippedOfTrailingZeros(),
            (string) BigDecimal::of($line->missing)->strippedOfTrailingZeros(),
        ]);

        // Two back on the shelf, in the batch they left from.
        $this->assertSame('19', $this->onHand($this->oil, $this->depotFg));
        $this->assertSame('19', $this->onHand($this->oil, $this->depotFg, $this->oilLot));

        // One in a damaged goods store opened for the depot.
        $damaged = Warehouse::query()->where('facility_id', $this->depot->id)->where('type', WarehouseType::Damaged->value)->sole();
        $this->assertSame('1', $this->onHand($this->oil, $damaged, $this->oilLot));
        $this->assertSame($damaged->id, $line->damaged_warehouse_id);

        $posting = InventoryTransaction::query()->where('type', InventoryTransactionType::MarketplaceReturn->value)->sole();
        $this->assertSame(ShipmentReturn::class, $posting->reference_type);
        $this->assertSame($posting->id, $return->inventory_transaction_id);

        // The parcel is returned, and a claim is open with Meesho's window.
        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Returned, $shipment->status);
        $this->assertSame(StockState::Returned, $shipment->stock_state);
        $this->assertNotNull($shipment->returned_at);
        $this->assertSame(ClaimStatus::Open, $return->claim_status);
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $return->claim_deadline_at->timestamp, 5);

        // A second damaged return reuses the same damaged store.
        $this->upload([$this->label('VL1000000000032')], 'second.pdf');
        $second = $this->packAndHandOver('VL1000000000032');
        $this->returns->receive($second, ReturnKind::Customer, [$this->oil->id => ['good' => 0, 'damaged' => 1]], $this->packer);
        $this->assertSame(1, Warehouse::query()->where('facility_id', $this->depot->id)->where('type', WarehouseType::Damaged->value)->count());
        $this->assertSame('2', $this->onHand($this->oil, $damaged));
    }

    #[Test]
    public function a_return_that_came_back_whole_opens_no_claim_and_a_short_one_does(): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'RR 500 ml', $this->oil, 1, $this->agency);
        $this->upload([$this->label('VL1000000000041', ['qty' => 2]), $this->label('VL1000000000042', ['qty' => 2])]);

        $whole = $this->returns->receive($this->packAndHandOver('VL1000000000041'), ReturnKind::Rto, [$this->oil->id => ['good' => 2]], $this->packer);
        $this->assertSame(ClaimStatus::None, $whole->claim_status);
        $this->assertSame(0, Warehouse::query()->where('type', WarehouseType::Damaged->value)->count());

        // One of two never came back: a claim, and nothing posted for it.
        $short = $this->returns->receive($this->packAndHandOver('VL1000000000042'), ReturnKind::Customer, [$this->oil->id => ['good' => 1]], $this->packer);
        $this->assertSame(ClaimStatus::Open, $short->claim_status);
        $this->assertSame('1', (string) BigDecimal::of($short->lines()->sole()->missing)->strippedOfTrailingZeros());
        $this->assertSame('19', $this->onHand($this->oil, $this->depotFg));

        $this->returns->updateClaim($short, ClaimStatus::Won, 'MSH-CLM-1', '399');
        $this->assertSame(ClaimStatus::Won, $short->refresh()->claim_status);
        $this->assertSame('MSH-CLM-1', $short->claim_reference);
    }

    #[Test]
    public function only_a_parcel_that_left_can_come_back_and_only_once(): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'RR 500 ml', $this->oil, 1, $this->agency);
        $this->upload([$this->label('VL1000000000051'), $this->label('VL1000000000052')]);

        $waiting = $this->orders->findByCode('VL1000000000051');
        $this->assertNotNull($this->returns->cannotReturn($waiting));

        try {
            $this->returns->receive($waiting, ReturnKind::Rto, [], $this->packer);
            $this->fail('A parcel never packed cannot come back.');
        } catch (OnlineOrderException $e) {
            $this->assertStringContainsString('never packed', $e->getMessage());
        }

        $shipment = $this->packAndHandOver('VL1000000000052');

        try {
            $this->returns->receive($shipment, ReturnKind::Rto, [$this->oil->id => ['good' => 1, 'damaged' => 1]], $this->packer);
            $this->fail('More cannot come back than left.');
        } catch (OnlineOrderException $e) {
            $this->assertStringContainsString('1 left in the parcel', $e->getMessage());
        }

        $this->returns->receive($shipment, ReturnKind::Rto, [$this->oil->id => ['good' => 1]], $this->packer);

        $this->expectException(OnlineOrderException::class);
        $this->returns->receive($shipment->refresh(), ReturnKind::Rto, [$this->oil->id => ['good' => 1]], $this->packer);
    }

    #[Test]
    public function a_returned_parcel_is_refused_at_the_packing_table(): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'RR 500 ml', $this->oil, 1, $this->agency);
        $this->upload([$this->label('VL1000000000061')]);
        $shipment = $this->packAndHandOver('VL1000000000061');
        $this->returns->receive($shipment, ReturnKind::Rto, [$this->oil->id => ['good' => 1]], $this->packer);

        $this->actingAs($this->packer)->postJson(route('floor.pack.scan'), ['code' => 'VL1000000000061'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    #[Test]
    public function the_depot_receives_a_return_on_screen_and_the_office_sees_it_with_its_claim(): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'RR 500 ml', $this->oil, 1, $this->agency);
        $this->upload([$this->label('VL1000000000071', ['qty' => 2])]);
        $shipment = $this->packAndHandOver('VL1000000000071');

        $this->actingAs($this->packer)->get(route('online-orders.returns.create', ['code' => 'VL1000000000071']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('online-orders/receive-return')->where('code', 'VL1000000000071')->has('stores', 1));

        $this->actingAs($this->packer)->getJson(route('online-orders.returns.lookup', ['code' => 'VL1000000000071']))
            ->assertOk()
            ->assertJsonPath('sent.0.item', 'Rahat Rooh 500 ml')
            ->assertJsonPath('sent.0.units', '2')
            ->assertJsonPath('why_not', null);

        $this->actingAs($this->packer)->post(route('online-orders.returns.store'), [
            'shipment_id' => $shipment->id,
            'kind' => 'customer',
            'lines' => [['item_id' => $this->oil->id, 'good' => '1', 'damaged' => '1']],
            'wrong_item' => false,
            'notes' => 'Cap broken',
        ])->assertRedirect(route('online-orders.returns.create'));

        $this->assertSame(ShipmentStatus::Returned, $shipment->refresh()->status);

        // The agency may not receive returns.
        $this->actingAs($this->agency)->get(route('online-orders.returns.create'))->assertRedirect(route('online-orders.index'));

        $this->actingAs($this->dispatcher)->get(route('online-orders.returns.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('online-orders/returns')
                ->has('returns', 1)
                ->where('returns.0.kind', 'customer')
                ->where('returns.0.claim_status', 'open')
                ->where('returns.0.lines.0.damaged', '1')
                ->where('summary.claims_open', 1));

        $return = ShipmentReturn::query()->sole();
        $this->actingAs($this->dispatcher)->patch(route('online-orders.returns.claim', $return), [
            'claim_status' => 'won', 'claim_reference' => 'TKT-77', 'claim_amount' => '199',
        ])->assertRedirect();
        $this->assertSame(ClaimStatus::Won, $return->refresh()->claim_status);

        $this->actingAs($this->dispatcher)->get(route('online-orders.index', ['show' => 'returned']))
            ->assertInertia(fn (Assert $page) => $page->has('parcels', 1)->where('totals.returned', 1));
    }

    #[Test]
    public function the_floor_phone_receives_a_return_and_comes_back_to_its_own_screen(): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'RR 500 ml', $this->oil, 1, $this->agency);
        $this->upload([$this->label('VL1000000000081', ['qty' => 1])]);
        $shipment = $this->packAndHandOver('VL1000000000081');

        $this->actingAs($this->packer)->get(route('floor.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.return', true));

        $this->actingAs($this->packer)->get(route('floor.return'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('floor/return')->has('kinds', 2)->has('stores', 1));

        $this->actingAs($this->packer)->post(route('online-orders.returns.store'), [
            'shipment_id' => $shipment->id,
            'kind' => 'rto',
            'lines' => [['item_id' => $this->oil->id, 'good' => '1', 'damaged' => '0']],
            'from' => 'floor',
        ])->assertRedirect(route('floor.return'));

        $this->assertSame(ShipmentStatus::Returned, $shipment->refresh()->status);

        // The agency has no floor return screen.
        $this->actingAs($this->agency)->get(route('floor.return'))->assertRedirect(route('online-orders.index'));
    }
}
