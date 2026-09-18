<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatch;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Contract\Models\Client;
use App\Domain\Dispatch\Enums\CustomerKind;
use App\Domain\Dispatch\Enums\DispatchStatus;
use App\Domain\Dispatch\Models\Customer;
use App\Domain\Dispatch\Models\Dispatch;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issue #17: finished goods leave the factory only from the finished goods
 * store, only once QC has released them, only to whoever they belong to,
 * and — for a GST-registered buyer — only with the e-invoice on record.
 */
class DispatchTest extends TestCase
{
    use RefreshDatabase;

    private Facility $factory;

    private Warehouse $fg;

    private Product $faceWash;

    private Product $clientProduct;

    private Client $hrbd;

    private Customer $marketplace;

    private Customer $sameState;

    private Customer $hrbdCustomer;

    private Customer $walkIn;

    private InventoryLot $ownLot;

    private InventoryLot $clientLot;

    private InventoryLot $pendingLot;

    private User $dispatcher;

    private User $management;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        config(['erp.company.brand' => 'Rahat Rooh', 'erp.dispatch.einvoice_mandatory' => true]);

        // Uttarakhand (05) is where the factory bills from.
        $this->factory = Facility::factory()->manufacturing()
            ->withStores([WarehouseType::RawMaterial, WarehouseType::FinishedGoods, WarehouseType::Quarantine])
            ->create(['name' => 'Rudrapur Factory', 'legal_name' => 'Harbanshram Bhagwandas Ayurvedic Sansthan Private Limited', 'gstin' => '05AAACH1234A1Z5', 'city' => 'Rudrapur', 'pincode' => '263153', 'can_dispatch' => true]);
        $this->fg = $this->factory->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();

        $pcs = Uom::where('code', 'PCS')->sole();
        $ml = Uom::where('code', 'ML')->sole();

        $this->hrbd = Client::factory()->create(['name' => 'HRBD Herbal Life Sciences', 'gstin' => '09AAACH5678B1Z3', 'is_active' => true]);

        $this->faceWash = Product::factory()->create(['name' => 'Rahat Rooh Face Wash 100 ml', 'stock_uom_id' => $pcs->id, 'net_content_uom_id' => $ml->id, 'hsn_code' => '33049990', 'gst_rate' => '18.000', 'mrp' => '249', 'client_id' => null]);
        $this->clientProduct = Product::factory()->create(['name' => 'Cleanse Ayurveda Face Wash 100 ml', 'stock_uom_id' => $pcs->id, 'net_content_uom_id' => $ml->id, 'hsn_code' => '33049990', 'gst_rate' => '18.000', 'client_id' => $this->hrbd->id]);

        $ledger = app(InventoryLedgerService::class);

        $this->ownLot = InventoryLot::factory()->forItem($this->faceWash)->create(['batch_number' => 'RR-260901-001', 'qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYears(2)]);
        $ledger->receive($this->faceWash, $this->fg, '500', $this->ownLot, InventoryTransactionType::ProductionOutput);

        $this->clientLot = InventoryLot::factory()->forItem($this->clientProduct)->create(['batch_number' => 'TP-001-260901-001', 'qc_status' => LotQcStatus::Approved, 'owner_client_id' => $this->hrbd->id, 'expiry_at' => now()->addYears(2)]);
        $ledger->receive($this->clientProduct, $this->fg, '200', $this->clientLot, InventoryTransactionType::ProductionOutput);

        $this->pendingLot = InventoryLot::factory()->forItem($this->faceWash)->create(['batch_number' => 'RR-260902-001', 'qc_status' => LotQcStatus::Pending]);
        $ledger->receive($this->faceWash, $this->fg, '50', $this->pendingLot, InventoryTransactionType::ProductionOutput);

        // A marketplace in Haryana (06): inter-state. A distributor in
        // Uttarakhand: intra-state. HRBD as a customer, linked to the client.
        // A walk-in buyer with no GSTIN.
        $this->marketplace = Customer::create(['code' => 'CUS-001', 'name' => 'Amazon Seller Services', 'legal_name' => 'Amazon Seller Services Pvt Ltd', 'gstin' => '06AAACA1234C1Z8', 'kind' => CustomerKind::Marketplace, 'billing_address_line_1' => 'FC 4', 'billing_city' => 'Gurugram', 'billing_pincode' => '122015', 'is_active' => true]);
        $this->sameState = Customer::create(['code' => 'CUS-002', 'name' => 'Haldwani Distributors', 'gstin' => '05AAACD9876D1Z1', 'kind' => CustomerKind::Distributor, 'billing_city' => 'Haldwani', 'is_active' => true]);
        $this->hrbdCustomer = Customer::create(['code' => 'CUS-003', 'name' => 'HRBD Herbal Life Sciences', 'gstin' => '09AAACH5678B1Z3', 'kind' => CustomerKind::Client, 'client_id' => $this->hrbd->id, 'billing_city' => 'Noida', 'is_active' => true]);
        $this->walkIn = Customer::create(['code' => 'CUS-004', 'name' => 'Walk-in buyer', 'kind' => CustomerKind::Other, 'is_active' => true]);

        $this->dispatcher = User::factory()->create(['name' => 'Dispatch Desk']);
        $this->dispatcher->assignRole(RoleName::DispatchManager->value);
        $this->management = User::factory()->create();
        $this->management->assignRole(RoleName::Management->value);
        $this->storekeeper = User::factory()->create();
        $this->storekeeper->assignRole(RoleName::StoreExecutive->value);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function payload(Customer $customer, array $lines, array $overrides = []): array
    {
        return [
            'warehouse_id' => $this->fg->id,
            'customer_id' => $customer->id,
            'reference' => 'PO/2026/42',
            'lines' => $lines,
            ...$overrides,
        ];
    }

    private function ownLine(string $quantity = '100', string $price = '150'): array
    {
        return ['item_id' => $this->faceWash->id, 'lot_id' => $this->ownLot->id, 'quantity' => $quantity, 'unit_price' => $price];
    }

    #[Test]
    public function the_dispatch_manager_writes_up_a_consignment_and_the_tax_follows_the_state(): void
    {
        // Haryana from Uttarakhand: IGST.
        $response = $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->marketplace, [
            $this->ownLine('100', '150'),
            ['item_id' => $this->faceWash->id, 'lot_id' => $this->ownLot->id, 'quantity' => '10', 'unit_price' => '150', 'discount_percent' => '10'],
        ]));

        $response->assertRedirect()->assertSessionHasNoErrors();
        $dispatch = Dispatch::sole();

        $this->assertSame(DispatchStatus::Draft, $dispatch->status);
        $this->assertTrue($dispatch->is_interstate);
        $this->assertSame('06', $dispatch->place_of_supply);
        $this->assertSame('16350.00', $dispatch->taxable_value, '100 × 150 + 10 × 150 less 10%');
        $this->assertSame('2943.00', $dispatch->igst);
        $this->assertSame('0.00', $dispatch->cgst);
        $this->assertSame('19293.00', $dispatch->total_value);
        $this->assertSame('33049990', $dispatch->lines->first()->hsn_code, 'HSN comes off the product');
        $this->assertSame('18.000', $dispatch->lines->first()->gst_rate);

        // Nothing has moved.
        $this->assertTrue(app(StockBalanceService::class)->availableForProduction($this->faceWash, [$this->fg->id])->isEqualTo(500));

        // Uttarakhand to Uttarakhand: CGST + SGST, halves to the paisa.
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->sameState, [$this->ownLine('1', '100.01')]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $intra = Dispatch::query()->latest('id')->firstOrFail();
        $this->assertFalse($intra->is_interstate);
        $this->assertSame('9.00', $intra->cgst);
        $this->assertSame('9.00', $intra->sgst);
        $this->assertSame('0.00', $intra->igst);
        $this->assertSame('100.01', $intra->taxable_value);
        $this->assertSame('118.00', $intra->total_value, 'rounded to the rupee');
        $this->assertSame('-0.01', $intra->round_off);
    }

    #[Test]
    public function only_released_batches_of_finished_goods_leave_and_a_clients_goods_only_to_them(): void
    {
        // Still in QC.
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->marketplace, [
            ['item_id' => $this->faceWash->id, 'lot_id' => $this->pendingLot->id, 'quantity' => '5', 'unit_price' => '150'],
        ]))->assertSessionHasErrors('lines');
        $this->assertStringContainsString('not released', session('errors')->first('lines'));

        // HRBD's goods to a marketplace: refused.
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->marketplace, [
            ['item_id' => $this->clientProduct->id, 'lot_id' => $this->clientLot->id, 'quantity' => '5', 'unit_price' => '90'],
        ]))->assertSessionHasErrors('lines');
        $this->assertStringContainsString('belongs to HRBD Herbal Life Sciences', session('errors')->first('lines'));

        // More than the store holds: refused, and it says how much is free.
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->marketplace, [$this->ownLine('501', '150')]))
            ->assertSessionHasErrors('lines');
        $this->assertStringContainsString('only 500', session('errors')->first('lines'));

        // HRBD's goods to HRBD: fine.
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->hrbdCustomer, [
            ['item_id' => $this->clientProduct->id, 'lot_id' => $this->clientLot->id, 'quantity' => '5', 'unit_price' => '90'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Dispatch::count());

        // Only the released batches are offered to the screen, with whose they are.
        $lots = $this->actingAs($this->dispatcher)->get(route('dispatches.lots', ['item' => $this->faceWash->id, 'store' => $this->fg->id]))->assertOk()->json();
        $this->assertCount(1, $lots);
        $this->assertSame('RR-260901-001', $lots[0]['batch']);
        $this->assertSame('Rahat Rooh', $lots[0]['owner']);
    }

    #[Test]
    public function nothing_leaves_for_a_registered_buyer_without_the_e_invoice_on_record(): void
    {
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->marketplace, [$this->ownLine('100', '150')]));
        $dispatch = Dispatch::sole();

        // A draft cannot leave.
        $this->actingAs($this->dispatcher)->post(route('dispatches.dispatch', $dispatch), [])->assertSessionHasErrors('dispatch');
        $this->assertStringContainsString('no invoice recorded', session('errors')->first('dispatch'));

        // The invoice alone is not enough for a registered buyer.
        $this->actingAs($this->dispatcher)->post(route('dispatches.invoice', $dispatch), [
            'invoice_number' => 'HB/26-27/0042', 'invoice_date' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(DispatchStatus::Invoiced, $dispatch->fresh()->status);

        $this->actingAs($this->dispatcher)->post(route('dispatches.dispatch', $dispatch), [])->assertSessionHasErrors('dispatch');
        $this->assertStringContainsString('IRN', session('errors')->first('dispatch'));

        // A malformed IRN is refused outright.
        $this->actingAs($this->dispatcher)->post(route('dispatches.invoice', $dispatch), [
            'invoice_number' => 'HB/26-27/0042', 'invoice_date' => now()->toDateString(), 'irn' => 'not-an-irn', 'ack_number' => '112010036563281', 'ack_date' => now()->toDateString(),
        ])->assertSessionHasErrors('irn');

        $irn = str_repeat('a1b2c3d4', 8);
        $this->actingAs($this->dispatcher)->post(route('dispatches.invoice', $dispatch), [
            'invoice_number' => 'HB/26-27/0042', 'invoice_date' => now()->toDateString(),
            'irn' => strtoupper($irn), 'ack_number' => '112010036563281', 'ack_date' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($irn, $dispatch->fresh()->irn, 'Stored lower-case, as the IRP issues it.');

        // Still not: the signed invoice has to be with the consignment.
        $this->actingAs($this->dispatcher)->post(route('dispatches.dispatch', $dispatch), [])->assertSessionHasErrors('dispatch');
        $this->assertStringContainsString('signed e-invoice', session('errors')->first('dispatch'));

        $this->actingAs($this->dispatcher)->post(route('dispatches.attachments.store', $dispatch), [
            'kind' => 'signed_invoice',
            'document' => UploadedFile::fake()->create('HB-26-27-0042-signed.pdf', 120, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(1, $dispatch->fresh()->attachments);
        Storage::disk('local')->assertExists($dispatch->fresh()->attachments->first()->path);

        // Now it goes, and the stock goes with it.
        $this->actingAs($this->dispatcher)->post(route('dispatches.dispatch', $dispatch), [
            'transporter_name' => 'Safexpress', 'vehicle_number' => 'uk06ab1234', 'eway_bill_number' => '123456789012', 'eway_bill_date' => now()->toDateString(), 'distance_km' => 260,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $dispatch->refresh();
        $this->assertSame(DispatchStatus::Dispatched, $dispatch->status);
        $this->assertSame('UK06AB1234', $dispatch->vehicle_number);
        $this->assertNotNull($dispatch->dispatched_at);
        $this->assertTrue(app(StockBalanceService::class)->availableForProduction($this->faceWash, [$this->fg->id])->isEqualTo(400));

        $posting = InventoryTransaction::query()->where('type', InventoryTransactionType::SalesDispatch->value)->sole();
        $this->assertSame($dispatch->id, $posting->reference_id);
        $this->assertStringContainsString('HB/26-27/0042', (string) $posting->reason);

        // Once gone it cannot be cancelled; it can be delivered.
        $this->actingAs($this->dispatcher)->post(route('dispatches.cancel', $dispatch))->assertSessionHasErrors('dispatch');
        $this->actingAs($this->dispatcher)->post(route('dispatches.deliver', $dispatch), ['note' => 'Signed POD received'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(DispatchStatus::Delivered, $dispatch->fresh()->status);

        // The challan prints.
        $this->actingAs($this->dispatcher)->get(route('dispatches.challan', $dispatch))->assertOk()->assertHeader('content-type', 'application/pdf');

        // And the consignment page shows the whole story.
        $this->actingAs($this->management)->get(route('dispatches.show', $dispatch))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dispatch/show')
                ->where('dispatch.status', 'delivered')
                ->where('dispatch.irn', $irn)
                ->where('einvoice.requires_irn', true)
                ->where('einvoice.ready', true)
                ->has('lines', 1)
                ->has('attachments', 1)
                ->where('can.dispatch', false)
                ->where('can.cancel', false));
    }

    #[Test]
    public function an_unregistered_buyer_is_invoiced_without_an_irn_but_the_invoice_still_travels(): void
    {
        // No GSTIN, so the place of supply has to be said.
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->walkIn, [$this->ownLine('2', '249')]))
            ->assertSessionHasErrors('lines');
        $this->assertStringContainsString('place of supply', session('errors')->first('lines'));

        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->walkIn, [$this->ownLine('2', '249')], ['place_of_supply' => '05']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $dispatch = Dispatch::sole();
        $this->assertFalse($dispatch->is_interstate);

        $this->actingAs($this->dispatcher)->post(route('dispatches.invoice', $dispatch), ['invoice_number' => 'HB/26-27/0043', 'invoice_date' => now()->toDateString()]);

        // No IRN asked for — but the invoice must be uploaded.
        $this->actingAs($this->dispatcher)->post(route('dispatches.dispatch', $dispatch), [])->assertSessionHasErrors('dispatch');
        $this->assertStringContainsString('invoice uploaded', session('errors')->first('dispatch'));
        $this->assertStringNotContainsString('IRN', session('errors')->first('dispatch'));

        $this->actingAs($this->dispatcher)->post(route('dispatches.attachments.store', $dispatch), [
            'kind' => 'invoice', 'document' => UploadedFile::fake()->create('invoice.pdf', 80, 'application/pdf'),
        ]);
        $this->actingAs($this->dispatcher)->post(route('dispatches.dispatch', $dispatch), [])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(DispatchStatus::Dispatched, $dispatch->fresh()->status);
    }

    #[Test]
    public function the_e_invoice_json_follows_the_irp_schema(): void
    {
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->marketplace, [$this->ownLine('100', '150')]));
        $dispatch = Dispatch::sole();

        // Nothing to build from until the invoice number is known.
        $this->actingAs($this->dispatcher)->get(route('dispatches.einvoice', $dispatch))->assertStatus(422);

        $this->actingAs($this->dispatcher)->post(route('dispatches.invoice', $dispatch), ['invoice_number' => 'HB/26-27/0042', 'invoice_date' => '2026-09-18']);

        $response = $this->actingAs($this->dispatcher)->get(route('dispatches.einvoice', $dispatch))->assertOk();
        $this->assertStringContainsString('einvoice-'.$dispatch->number.'.json', (string) $response->headers->get('Content-Disposition'));

        $json = $response->json();
        $this->assertCount(1, $json, 'The IRP bulk format is a list of documents.');
        $doc = $json[0];

        $this->assertSame('1.1', $doc['Version']);
        $this->assertSame('B2B', $doc['TranDtls']['SupTyp']);
        $this->assertSame(['Typ' => 'INV', 'No' => 'HB/26-27/0042', 'Dt' => '18/09/2026'], $doc['DocDtls']);
        $this->assertSame('05AAACH1234A1Z5', $doc['SellerDtls']['Gstin']);
        $this->assertSame('Harbanshram Bhagwandas Ayurvedic Sansthan Private Limited', $doc['SellerDtls']['LglNm']);
        $this->assertSame('05', $doc['SellerDtls']['Stcd']);
        $this->assertSame(263153, $doc['SellerDtls']['Pin']);
        $this->assertSame('06AAACA1234C1Z8', $doc['BuyerDtls']['Gstin']);
        $this->assertSame('Amazon Seller Services Pvt Ltd', $doc['BuyerDtls']['LglNm']);
        $this->assertSame('06', $doc['BuyerDtls']['Pos']);

        $item = $doc['ItemList'][0];
        $this->assertSame('1', $item['SlNo']);
        $this->assertSame('33049990', $item['HsnCd']);
        $this->assertSame('NOS', $item['Unit'], 'PCS becomes the GST unit code NOS');
        $this->assertEquals(100.0, $item['Qty']);
        $this->assertEquals(150.0, $item['UnitPrice']);
        $this->assertEquals(15000.0, $item['AssAmt']);
        $this->assertEquals(18.0, $item['GstRt']);
        $this->assertEquals(2700.0, $item['IgstAmt']);
        $this->assertEquals(0.0, $item['CgstAmt']);
        $this->assertEquals(17700.0, $item['TotItemVal']);
        $this->assertSame('RR-260901-001', $item['BchDtls']['Nm']);

        $this->assertEquals(15000.0, $doc['ValDtls']['AssVal']);
        $this->assertEquals(2700.0, $doc['ValDtls']['IgstVal']);
        $this->assertEquals(17700.0, $doc['ValDtls']['TotInvVal']);
    }

    #[Test]
    public function dispatch_is_the_dispatch_managers_and_management_only_looks(): void
    {
        $this->actingAs($this->storekeeper)->get(route('dispatches.index'))->assertForbidden();
        $this->actingAs($this->storekeeper)->get(route('dispatches.create'))->assertForbidden();
        $this->actingAs($this->storekeeper)->get(route('customers.index'))->assertForbidden();

        $this->actingAs($this->management)->get(route('dispatches.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.create', false));
        $this->actingAs($this->management)->get(route('dispatches.create'))->assertForbidden();
        $this->actingAs($this->management)->post(route('dispatches.store'), $this->payload($this->marketplace, [$this->ownLine()]))->assertForbidden();

        $this->actingAs($this->dispatcher)->get(route('dispatches.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dispatch/create')
                ->has('stores', 1)
                ->where('stores.0.bills_as', 'Harbanshram Bhagwandas Ayurvedic Sansthan Private Limited')
                ->where('stores.0.seller_state_code', '05')
                ->has('customers', 4)
                ->has('items', 2)
                ->where('einvoiceMandatory', true));

        // The dispatch manager sees nothing of production or recipes.
        $this->assertFalse($this->dispatcher->can('production.view'));
        $this->assertFalse($this->dispatcher->can('formula.view'));
        $this->assertTrue($this->dispatcher->can('dispatch.dispatch'));
    }

    #[Test]
    public function customers_are_kept_by_the_dispatch_desk_and_the_history_shows_totals(): void
    {
        $this->actingAs($this->dispatcher)->post(route('customers.store'), [
            'name' => 'Flipkart India', 'legal_name' => 'Flipkart India Private Limited', 'gstin' => '29AAACF1234E1Z2', 'kind' => 'marketplace',
            'billing_city' => 'Bengaluru', 'billing_state' => 'Karnataka', 'billing_pincode' => '560103',
        ])->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();

        $flipkart = Customer::query()->where('name', 'Flipkart India')->sole();
        $this->assertSame('CUS-005', $flipkart->code);
        $this->assertSame('29', $flipkart->stateCode());

        // A GSTIN is one legal entity.
        $this->actingAs($this->dispatcher)->post(route('customers.store'), ['name' => 'Another', 'gstin' => '29AAACF1234E1Z2', 'kind' => 'other'])
            ->assertSessionHasErrors('gstin');

        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($this->marketplace, [$this->ownLine('100', '150')]));
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), $this->payload($flipkart, [$this->ownLine('50', '150')]));
        $cancelled = Dispatch::query()->latest('id')->firstOrFail();
        $this->actingAs($this->dispatcher)->post(route('dispatches.cancel', $cancelled), ['reason' => 'Order withdrawn'])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->management)->get(route('dispatches.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dispatch/index')
                ->has('dispatches.data', 2)
                ->where('summary.consignments', 1)
                ->where('summary.taxable', '15000.00')
                ->where('summary.tax', '2700.00')
                ->where('summary.total', '17700.00'));

        $this->actingAs($this->management)->get(route('dispatches.index', ['customer' => $flipkart->id, 'status' => 'cancelled']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('dispatches.data', 1)->where('summary.consignments', 0));
    }
}
