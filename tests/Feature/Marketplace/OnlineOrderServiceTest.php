<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Contract\Models\Client;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Enums\PaymentMode;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\LabelPrint;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Brick\Math\BigDecimal;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeLabelReader;
use Tests\Support\LabelFixtures;
use Tests\TestCase;

/**
 * The online-orders protocol, service by service: labels in, stock held,
 * printed by courier, packed by scan with the stock leaving then, handed to
 * the courier — and every way it can go wrong said in plain words.
 */
class OnlineOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Facility $depot;

    private Warehouse $depotFg;

    private Product $oil;

    private InventoryLot $older;

    private InventoryLot $newer;

    private Brand $rahatRooh;

    private Marketplace $meesho;

    private Marketplace $amazon;

    private User $agency;

    private User $packer;

    private OnlineOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        config(['erp.company.brand' => 'Rahat Rooh']);
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));

        // The Delhi depot bills from Delhi (07).
        $this->depot = Facility::factory()
            ->withStores([WarehouseType::FinishedGoods])
            ->create(['name' => 'Paper Market Depot', 'gstin' => '07AAAAA0000A1Z5', 'can_dispatch' => true]);
        $this->depotFg = $this->depot->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();

        // Brands and marketplaces arrive with the reference data, and the
        // brand's parcels leave from Paper Market by default.
        $this->seed(ReferenceDataSeeder::class);
        $this->rahatRooh = Brand::query()->where('code', 'RR')->sole();
        $this->meesho = Marketplace::query()->where('code', 'MEESHO')->sole();
        $this->amazon = Marketplace::query()->where('code', 'AMAZON')->sole();

        $pcs = Uom::where('code', 'PCS')->sole();
        $this->oil = Product::factory()->create(['name' => 'Rahat Rooh Hair Oil 200 ml', 'stock_uom_id' => $pcs->id, 'client_id' => null]);

        $ledger = app(InventoryLedgerService::class);
        $this->older = InventoryLot::factory()->forItem($this->oil)->create(['batch_number' => 'FG260801-001', 'qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        $this->newer = InventoryLot::factory()->forItem($this->oil)->create(['batch_number' => 'FG260901-001', 'qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYears(2)]);
        $ledger->receive($this->oil, $this->depotFg, '3', $this->older, InventoryTransactionType::StockAdjustmentIn);
        $ledger->receive($this->oil, $this->depotFg, '20', $this->newer, InventoryTransactionType::StockAdjustmentIn);

        $this->agency = User::factory()->create(['name' => 'Agency Desk']);
        $this->agency->assignRole(RoleName::EcommerceAgency->value);
        $this->agency->brands()->attach($this->rahatRooh);

        $this->packer = User::factory()->create(['name' => 'Packing Table']);
        $this->packer->assignRole(RoleName::StoreExecutive->value);

        $this->orders = app(OnlineOrderService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function parcel(string $awb, array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return [
            'awb' => $awb,
            'courier' => 'Valmo',
            'payment' => 'cod',
            'sku' => 'Hair oil 200 ml',
            'qty' => 1,
            'order' => sprintf('1000000000000%05d', $n),
            'invoice' => sprintf('abcde%04d', $n),
            'total' => '199.00',
            'name' => "Test Customer {$n}",
            'state' => 'Uttar Pradesh',
            ...$overrides,
        ];
    }

    private function mapOil(int $units = 1): void
    {
        $this->orders->mapSku($this->meesho, $this->rahatRooh, 'Hair oil 200 ml', $this->oil, $units, $this->agency);
    }

    private function free(): string
    {
        return (string) app(StockBalanceService::class)->available($this->oil, $this->depotFg)->strippedOfTrailingZeros();
    }

    #[Test]
    public function the_brand_ships_from_the_paper_market_depot_by_default(): void
    {
        $this->assertSame($this->depotFg->id, $this->rahatRooh->default_warehouse_id);
        $this->assertSame(['AMAZON', 'FLIPKART', 'MEESHO', 'MYNTRA'], Marketplace::query()->orderBy('code')->pluck('code')->all());
    }

    #[Test]
    public function an_upload_turns_each_label_into_a_parcel_read_from_its_text(): void
    {
        $batch = $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([
                $this->parcel('VL0000000000001', ['qty' => 2]),
                $this->parcel('SF0000000001FPL', ['courier' => 'Shadowfax', 'payment' => 'prepaid']),
            ]),
        ], $this->agency);

        $this->assertMatchesRegularExpression('/^LB-\d{4}-00001$/', $batch->number);
        $this->assertSame($this->depot->id, $batch->facility_id);
        $this->assertCount(1, $batch->files);
        Storage::disk('local')->assertExists($batch->files->first()->path);
        $this->assertSame('meesho', $batch->files->first()->read_with);

        $shipments = $batch->shipments()->with('lines')->orderBy('id')->get();
        $this->assertCount(2, $shipments);

        [$first, $second] = [$shipments[0], $shipments[1]];
        $this->assertSame('VL0000000000001', $first->awb);
        $this->assertSame('Valmo', $first->courier);
        $this->assertSame(PaymentMode::Cod, $first->payment_mode);
        $this->assertSame([1], $first->pages);
        $this->assertSame('Hair oil 200 ml', $first->lines->first()->seller_sku);
        $this->assertSame(2, $first->lines->first()->quantity);
        $this->assertSame('Shadowfax', $second->courier);
        $this->assertSame(PaymentMode::Prepaid, $second->payment_mode);
        $this->assertSame([2], $second->pages);

        // Nobody has said which product "Hair oil 200 ml" is yet.
        $this->assertSame(StockState::Unmapped, $first->stock_state);
        $this->assertSame(ShipmentStatus::Uploaded, $first->status);
        $this->assertSame('23', $this->free());
    }

    #[Test]
    public function mapping_a_sku_once_holds_the_stock_for_every_waiting_parcel_and_the_next_upload(): void
    {
        $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001', ['qty' => 2]), $this->parcel('VL0000000000002')]),
        ], $this->agency);

        $this->mapOil();

        $this->assertSame(2, Shipment::query()->where('stock_state', StockState::Reserved->value)->count());
        $this->assertSame('20', $this->free());

        // Tomorrow's label with the same SKU text is matched without asking,
        // however its spacing and case come out.
        $batch = $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000003', ['sku' => 'HAIR OIL  200 ml'])], 'next.pdf'),
        ], $this->agency);

        $this->assertSame(StockState::Reserved, $batch->shipments()->where('awb', 'VL0000000000003')->sole()->stock_state);
        $this->assertSame('19', $this->free());
    }

    #[Test]
    public function a_pack_of_two_listing_takes_two_units_per_order(): void
    {
        $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001', ['qty' => 3])]),
        ], $this->agency);

        $this->mapOil(units: 2);

        $line = Shipment::query()->sole()->lines()->sole();
        $this->assertSame('6', rtrim(rtrim((string) $line->units, '0'), '.'));
        $this->assertSame('17', $this->free());
    }

    #[Test]
    public function labels_print_grouped_by_courier_and_each_print_is_logged(): void
    {
        $batch = $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([
                $this->parcel('VL0000000000001'),
                $this->parcel('SF0000000001FPL', ['courier' => 'Shadowfax']),
                $this->parcel('VL0000000000002'),
                $this->parcel('1490000000000001', ['courier' => 'Delhivery']),
            ]),
        ], $this->agency);

        $plan = $this->orders->print($batch, 'unprinted', $this->agency);

        $this->assertSame(4, $plan['shipments']);
        $this->assertSame(['Delhivery', 'Shadowfax', 'Valmo', 'Valmo'], array_column($plan['parts'], 'courier'));
        $this->assertSame([[4], [2], [1], [3]], array_column($plan['parts'], 'pages'));
        $this->assertSame(4, Shipment::query()->where('status', ShipmentStatus::Printed->value)->count());
        $this->assertSame(1, LabelPrint::query()->count());

        // Only Valmo's pile, again: counted as a reprint.
        $again = $this->orders->print($batch, 'courier', $this->agency, 'Valmo');
        $this->assertSame(2, $again['shipments']);
        $this->assertSame([2, 2], Shipment::query()->where('courier', 'Valmo')->pluck('print_count')->all());
        $this->assertSame([1, 1], Shipment::query()->where('courier', '<>', 'Valmo')->pluck('print_count')->all());

        $this->expectException(OnlineOrderException::class);
        $this->orders->print($batch, 'unprinted', $this->agency);
    }

    #[Test]
    public function packing_takes_the_stock_out_through_the_ledger_oldest_batch_first(): void
    {
        $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001', ['qty' => 5])]),
        ], $this->agency);
        $this->mapOil();

        $shipment = $this->orders->findByCode('vl0000000000001');
        $this->assertNotNull($shipment);

        $packed = $this->orders->pack($shipment, $this->packer);

        $this->assertSame(ShipmentStatus::Packed, $packed->status);
        $this->assertSame(StockState::Consumed, $packed->stock_state);
        $this->assertSame('scan', $packed->pack_method);
        $this->assertSame($this->packer->id, $packed->packed_by);

        $posting = InventoryTransaction::query()->where('type', InventoryTransactionType::MarketplaceSale->value)->with('lines')->sole();
        $this->assertSame(Shipment::class, $posting->reference_type);
        $this->assertStringContainsString('VL0000000000001', (string) $posting->reason);

        // Three from the batch expiring first, two from the next.
        $byLot = $posting->lines->mapWithKeys(fn ($l) => [$l->lot_id => (string) BigDecimal::of($l->quantity)->strippedOfTrailingZeros()])->all();
        $this->assertSame(['-3', '-2'], [$byLot[$this->older->id], $byLot[$this->newer->id]]);

        $balances = app(StockBalanceService::class);
        $this->assertSame('18', (string) $balances->onHand($this->oil, $this->depotFg)->strippedOfTrailingZeros());
        $this->assertSame('18', $this->free());
    }

    #[Test]
    public function a_parcel_cannot_be_packed_twice_or_after_it_was_cancelled(): void
    {
        $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001'), $this->parcel('VL0000000000002')]),
        ], $this->agency);
        $this->mapOil();

        $this->orders->pack($this->orders->findByCode('VL0000000000001'), $this->packer);

        try {
            $this->orders->pack($this->orders->findByCode('VL0000000000001'), $this->packer);
            $this->fail('Packed twice.');
        } catch (OnlineOrderException $e) {
            $this->assertStringContainsString('Already packed by Packing Table', $e->getMessage());
        }

        $second = $this->orders->findByCode('VL0000000000002');
        $this->orders->cancel($second, 'Customer cancelled on Meesho', $this->agency);
        $this->assertSame('22', $this->free());

        try {
            $this->orders->pack($this->orders->findByCode('VL0000000000002'), $this->packer);
            $this->fail('Packed a cancelled parcel.');
        } catch (OnlineOrderException $e) {
            $this->assertStringContainsString('Do not pack this parcel', $e->getMessage());
            $this->assertStringContainsString('Customer cancelled on Meesho', $e->getMessage());
        }

        $this->assertSame(1, InventoryTransaction::query()->where('type', InventoryTransactionType::MarketplaceSale->value)->count());
    }

    #[Test]
    public function a_parcel_the_store_cannot_cover_is_marked_short_and_held_once_stock_arrives(): void
    {
        $batch = $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001', ['qty' => 20]), $this->parcel('VL0000000000002', ['qty' => 10])]),
        ], $this->agency);
        $this->mapOil();

        $short = Shipment::query()->where('awb', 'VL0000000000002')->sole();
        $this->assertSame(StockState::Short, $short->stock_state);
        $this->assertSame(StockState::Reserved, Shipment::query()->where('awb', 'VL0000000000001')->sole()->stock_state);

        $this->assertSame([[
            'item_id' => $this->oil->id,
            'code' => $this->oil->code,
            'name' => 'Rahat Rooh Hair Oil 200 ml',
            'unit' => 'PCS',
            'needed' => '10',
            'free' => '3',
            'short' => '7',
        ]], $this->orders->shortfall($batch));

        try {
            $this->orders->pack($short, $this->packer);
            $this->fail('Packed without stock.');
        } catch (OnlineOrderException $e) {
            $this->assertStringContainsString('Not enough Rahat Rooh Hair Oil 200 ml in', $e->getMessage());
        }

        $lot = InventoryLot::factory()->forItem($this->oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYears(2)]);
        app(InventoryLedgerService::class)->receive($this->oil, $this->depotFg, '10', $lot, InventoryTransactionType::StockAdjustmentIn);

        $this->assertSame(['held' => 1, 'short' => 0, 'unmapped' => 0], $this->orders->holdAgain($batch));
        $this->assertSame([], $this->orders->shortfall($batch));
    }

    #[Test]
    public function the_same_file_is_refused_and_a_label_already_uploaded_is_skipped(): void
    {
        $file = LabelFixtures::meesho([$this->parcel('VL0000000000001')]);
        $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [$file], $this->agency);

        try {
            $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [$file], $this->agency);
            $this->fail('The same file went in twice.');
        } catch (OnlineOrderException $e) {
            $this->assertStringContainsString('was already uploaded in LB-', $e->getMessage());
        }

        // A re-download from the portal: a different file carrying one
        // label already in and one new one.
        $batch = $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001'), $this->parcel('VL0000000000009')], 'again.pdf'),
        ], $this->agency);

        $this->assertSame(2, Shipment::query()->count());
        $warnings = $batch->files()->reorder('id', 'desc')->first()->warnings;
        $this->assertStringContainsString('1 label(s) in this file were already uploaded earlier and were skipped: VL0000000000001', $warnings[0]);
    }

    #[Test]
    public function cancelling_after_packing_puts_the_goods_back_and_after_handover_is_refused(): void
    {
        $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001', ['qty' => 2]), $this->parcel('VL0000000000002')]),
        ], $this->agency);
        $this->mapOil();

        $first = $this->orders->pack($this->orders->findByCode('VL0000000000001'), $this->packer);
        $this->assertSame('20', $this->free());

        $this->orders->cancel($first, 'Order cancelled before pickup', $this->packer);
        $this->assertSame('22', $this->free());
        $this->assertSame(1, InventoryTransaction::query()->where('type', InventoryTransactionType::Reversal->value)->count());

        $second = $this->orders->pack($this->orders->findByCode('VL0000000000002'), $this->packer);
        $sheet = $this->orders->handOver($this->depotFg, 'Valmo', [$second->id], $this->packer, 'Ramesh (Valmo)');

        $this->assertMatchesRegularExpression('/^HO-\d{4}-00001$/', $sheet->number);
        $this->assertSame(1, $sheet->shipment_count);
        $this->assertSame(ShipmentStatus::HandedOver, $second->refresh()->status);

        $this->expectExceptionMessage('It comes back as a return, not a cancellation.');
        $this->orders->cancel($second, 'Too late', $this->packer);
    }

    #[Test]
    public function only_packed_parcels_can_be_handed_over(): void
    {
        $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001')]),
        ], $this->agency);

        $this->expectExceptionMessage('Only packed parcels can be handed over. Not packed: VL0000000000001.');
        $this->orders->handOver($this->depotFg, 'Valmo', [Shipment::query()->sole()->id], $this->packer);
    }

    #[Test]
    public function picture_only_labels_go_to_the_ai_reader_which_groups_a_label_with_its_invoice_pages(): void
    {
        $reader = new FakeLabelReader([
            ['pages' => [1, 2], 'awb' => '372700000001', 'order_number' => '402-0000001-0000001', 'courier' => 'Amazon Shipping', 'payment_mode' => 'prepaid', 'lines' => [['seller_sku' => 'Hair_Oil_200ml', 'description' => 'Hair oil', 'quantity' => 1]]],
            ['pages' => [3, 4, 5], 'awb' => '372700000002', 'order_number' => '404-0000002-0000002', 'courier' => 'Amazon Shipping', 'payment_mode' => 'cod', 'lines' => [['seller_sku' => 'Hair_Oil_200ml_Po2', 'description' => 'Hair oil pack of 2', 'quantity' => 1]]],
        ]);
        $this->app->instance(AiLabelReader::class, $reader);
        $orders = app(OnlineOrderService::class);

        $batch = $orders->upload($this->rahatRooh, $this->amazon, $this->depotFg, [LabelFixtures::imageOnly(5)], $this->agency);

        $this->assertSame([['filename' => 'amazon_labels.pdf', 'marketplace' => 'Amazon', 'pages' => 5]], $reader->calls);
        $this->assertSame('ai', $batch->files->first()->read_with);
        $this->assertSame([[1, 2], [3, 4, 5]], $batch->shipments()->orderBy('id')->get()->pluck('pages')->all());

        $orders->mapSku($this->amazon, $this->rahatRooh, 'Hair_Oil_200ml_Po2', $this->oil, 2, $this->agency);
        $this->assertSame(StockState::Reserved, Shipment::query()->where('awb', '372700000002')->sole()->stock_state);
        $this->assertSame('21', $this->free());
    }

    #[Test]
    public function without_the_ai_reader_a_picture_page_still_becomes_a_parcel_someone_can_fill_in(): void
    {
        $batch = $this->orders->upload($this->rahatRooh, $this->amazon, $this->depotFg, [LabelFixtures::imageOnly(1, 'myntra.pdf')], $this->agency);

        $parcel = $batch->shipments()->sole();
        $this->assertNull($parcel->awb);
        $this->assertStringContainsString('could not be read', $parcel->warnings[0]);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $batch->files->first()->warnings[0]);

        $this->orders->mapSku($this->amazon, $this->rahatRooh, 'Hair_Oil_200ml', $this->oil, 1, $this->agency);
        $parcel = $this->orders->correct($parcel, [
            'awb' => 'myec 1100000001',
            'courier' => 'Ekart',
            'payment_mode' => 'cod',
            'lines' => [['seller_sku' => 'Hair_Oil_200ml', 'quantity' => 2]],
        ]);

        $this->assertSame('MYEC1100000001', $parcel->awb);
        $this->assertSame(StockState::Reserved, $parcel->stock_state);
        $this->assertSame('21', $this->free());
    }

    #[Test]
    public function labels_registered_in_another_state_are_flagged_against_the_store_they_leave(): void
    {
        $flipkart = Marketplace::query()->where('code', 'FLIPKART')->sole();

        $batch = $this->orders->upload($this->rahatRooh, $flipkart, $this->depotFg, [
            LabelFixtures::flipkart([[
                'awb' => 'FMPC0000000001', 'payment' => 'COD', 'sku' => 'Hair_Oil_200', 'description' => 'Test Hair Oil 200',
                'qty' => 1, 'order' => 'OD100000000000000001', 'invoice' => 'FAKEINV0001', 'total' => '216.00', 'name' => 'Mira Test', 'state' => 'OR',
                'gstin' => '05AAAAA0000A1Z5',
            ]]),
        ], $this->agency);

        $warning = $batch->files->first()->warnings[0];
        $this->assertStringContainsString('registered to ship from Uttarakhand', $warning);
        $this->assertStringContainsString('but the stock will leave', $warning);
    }

    #[Test]
    public function a_brand_selling_a_clients_goods_only_takes_that_clients_batches(): void
    {
        $hrbd = Client::factory()->create(['name' => 'HRBD Herbal Life Sciences']);
        $cleanse = Brand::query()->where('code', 'CA')->sole();
        $cleanse->update(['client_id' => $hrbd->id]);

        $this->orders->upload($cleanse, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001')]),
        ], $this->agency->refresh());
        $this->orders->mapSku($this->meesho, $cleanse, 'Hair oil 200 ml', $this->oil, 1, $this->agency);

        // Only company stock is on the shelf: HRBD's parcel cannot take it.
        $this->assertSame(StockState::Short, Shipment::query()->sole()->stock_state);

        $theirs = InventoryLot::factory()->forItem($this->oil)->create(['qc_status' => LotQcStatus::Approved, 'owner_client_id' => $hrbd->id, 'expiry_at' => now()->addYear()]);
        app(InventoryLedgerService::class)->receive($this->oil, $this->depotFg, '5', $theirs, InventoryTransactionType::StockAdjustmentIn);

        $this->assertSame(StockState::Reserved, $this->orders->hold(Shipment::query()->sole()));
        $this->assertSame($theirs->id, Shipment::query()->sole()->reservations()->sole()->lot_id);
    }

    #[Test]
    public function a_file_can_be_taken_back_out_until_something_in_it_is_printed(): void
    {
        $batch = $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001')]),
        ], $this->agency);
        $this->mapOil();
        $file = $batch->files->first();

        $this->orders->removeFile($file);

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame('23', $this->free());
        Storage::disk('local')->assertMissing($file->path);

        $batch = $this->orders->upload($this->rahatRooh, $this->meesho, $this->depotFg, [
            LabelFixtures::meesho([$this->parcel('VL0000000000001')], 'again.pdf'),
        ], $this->agency);
        $this->orders->print($batch, 'all', $this->agency);

        $this->expectExceptionMessage('some of its labels have already been printed or packed');
        $this->orders->removeFile($batch->files()->first());
    }
}
