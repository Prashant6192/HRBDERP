<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Contract\Models\Client;
use App\Domain\Dispatch\Enums\CustomerKind;
use App\Domain\Dispatch\Enums\DispatchStatus;
use App\Domain\Dispatch\Models\Customer;
use App\Domain\Dispatch\Models\Dispatch;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Inventory\Services\StockTransferService;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Launch day, end to end, the way the factory will actually do it.
 *
 * The factory at Rudrapur is Harbanshram Bhagwandas Ayurvedic Sansthan
 * Private Limited. It makes Cleanse Ayurveda products for HRBD Herbal Life
 * Sciences under contract, and its own brand Rahat Rooh. Own-brand goods go
 * to the Paper Market depot, which is HRBD's, and from there to the
 * marketplaces.
 *
 *   1. The stock on the shelves goes in from counting sheets — raw
 *      material, packaging and finished goods — with QC passed.
 *   2. A third-party batch for HRBD is planned; the one short material is
 *      requested, delivered, QC-released.
 *   3. The batch is made, comes out of the kettle into quarantine, passes
 *      QC, lands in the finished goods store in HRBD's name.
 *   4. Rahat Rooh stock moves to the Paper Market depot on a challan and is
 *      booked in there.
 *   5. HRBD's goods are dispatched to HRBD from the factory and Rahat Rooh
 *      goods to a marketplace from the depot, each with the e-invoice on
 *      record before anything leaves.
 *   6. Management sees all of it.
 *
 * Every step is a request to the screens the people will use, under the
 * roles they will hold.
 */
class LaunchRehearsalTest extends TestCase
{
    use RefreshDatabase;

    private Facility $rudrapur;

    private Facility $paperMarket;

    private Warehouse $rm;

    private Warehouse $pm;

    private Warehouse $fg;

    private Warehouse $depotFg;

    private User $admin;

    private User $storekeeper;

    private User $qc;

    private User $production;

    private User $plantHead;

    private User $dispatcher;

    private User $depotKeeper;

    private User $management;

    private Client $hrbd;

    private RawMaterial $betaine;

    private RawMaterial $water;

    private PackagingMaterial $bottle;

    private Product $cleanse;

    private Product $rahatRooh;

    private Formula $formula;

    private Uom $kg;

    private Uom $pcs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        config(['erp.company.name' => 'HRBD', 'erp.company.brand' => 'Rahat Rooh', 'erp.dispatch.einvoice_mandatory' => true]);

        $this->kg = Uom::where('code', 'KG')->sole();
        $this->pcs = Uom::where('code', 'PCS')->sole();
        $ml = Uom::where('code', 'ML')->sole();
        $g = Uom::where('code', 'G')->sole();

        // -- The two sites ------------------------------------------------
        $this->rudrapur = Facility::factory()->manufacturing()
            ->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::FinishedGoods, WarehouseType::Quarantine])
            ->create([
                'code' => 'FAC-RDP-001', 'name' => 'Rudrapur Factory',
                'legal_name' => 'Harbanshram Bhagwandas Ayurvedic Sansthan Private Limited',
                'gstin' => '05AAACH1234A1Z5', 'address_line_1' => 'Plot 12, SIDCUL', 'city' => 'Rudrapur', 'state' => 'Uttarakhand', 'pincode' => '263153',
                'opening_stock_enabled' => true, 'can_dispatch' => true,
            ]);
        $this->rm = $this->rudrapur->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        $this->pm = $this->rudrapur->stores()->where('type', WarehouseType::Packaging->value)->firstOrFail();
        $this->fg = $this->rudrapur->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();

        $this->paperMarket = Facility::factory()
            ->withStores([WarehouseType::FinishedGoods])
            ->create([
                'code' => 'FAC-PPM-001', 'name' => 'Paper Market Depot',
                'legal_name' => 'HRBD Herbal Life Sciences',
                'gstin' => '07AAACH5678B1Z7', 'city' => 'Delhi', 'state' => 'Delhi', 'pincode' => '110006',
                'can_manufacture' => false, 'can_dispatch' => true,
            ]);
        $this->depotFg = $this->paperMarket->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();

        // -- The people, with their employee roles -------------------------
        $this->admin = $this->person('Prashant', RoleName::SuperAdmin);
        $this->storekeeper = $this->person('Store Executive', RoleName::StoreExecutive, $this->rudrapur);
        $this->qc = $this->person('QC Manager', RoleName::QcManager, $this->rudrapur);
        $this->qc->setFormulaPin('2468');
        $this->production = $this->person('Production Manager', RoleName::ProductionManager, $this->rudrapur);
        $this->plantHead = $this->person('Factory Manager', RoleName::FactoryManager, $this->rudrapur);
        $this->dispatcher = $this->person('Dispatch Manager', RoleName::DispatchManager);
        $this->depotKeeper = $this->person('Paper Market Keeper', RoleName::WarehouseManager, $this->paperMarket);
        $this->management = $this->person('Management', RoleName::Management);

        // -- Masters --------------------------------------------------------
        $this->hrbd = Client::factory()->create(['name' => 'HRBD Herbal Life Sciences', 'legal_name' => 'HRBD Herbal Life Sciences', 'gstin' => '09AAACH5678B1Z3', 'billing_city' => 'Noida', 'billing_state' => 'Uttar Pradesh', 'is_active' => true]);
        Vendor::factory()->create(['name' => 'Cladic Chemicals', 'is_approved' => true, 'is_active' => true]);

        $this->betaine = RawMaterial::factory()->create(['code' => 'RM-0001', 'name' => 'Cocamidopropyl Betaine', 'stock_uom_id' => $this->kg->id, 'requires_qc' => true, 'shelf_life_days' => 730, 'reorder_level' => null, 'minimum_stock' => null]);
        $this->water = RawMaterial::factory()->create(['code' => 'RM-0002', 'name' => 'Purified Water', 'stock_uom_id' => $this->kg->id, 'density_g_per_ml' => '1', 'requires_qc' => false, 'reorder_level' => null, 'minimum_stock' => null]);
        $this->bottle = PackagingMaterial::factory()->create(['code' => 'PM-0001', 'name' => 'Bottle 100 ml', 'stock_uom_id' => $this->pcs->id, 'requires_qc' => false, 'reorder_level' => null, 'minimum_stock' => null]);

        $this->cleanse = Product::factory()->create([
            'code' => 'FG-0001', 'name' => 'Cleanse Ayurveda Face Wash 100 ml', 'brand' => 'Cleanse Ayurveda', 'client_id' => $this->hrbd->id,
            'net_content' => '100', 'net_content_uom_id' => $ml->id, 'stock_uom_id' => $this->pcs->id,
            'requires_qc' => true, 'shelf_life_days' => 730, 'hsn_code' => '33049990', 'gst_rate' => '18.000', 'mrp' => '299',
        ]);
        $this->cleanse->packagingLines()->create(['packaging_material_id' => $this->bottle->id, 'quantity_per_unit' => '1']);

        $this->rahatRooh = Product::factory()->create([
            'code' => 'FG-0002', 'name' => 'Rahat Rooh Hair Oil 100 ml', 'brand' => 'Rahat Rooh', 'client_id' => null,
            'net_content' => '100', 'net_content_uom_id' => $ml->id, 'stock_uom_id' => $this->pcs->id,
            'requires_qc' => true, 'shelf_life_days' => 1095, 'hsn_code' => '33059019', 'gst_rate' => '18.000', 'mrp' => '349',
        ]);

        $formulas = app(FormulaService::class);
        $this->formula = $formulas->create(
            ['name' => 'Cleanse Ayurveda Face Wash', 'product_id' => $this->cleanse->id, 'batch_uom_id' => $g->id, 'client_id' => $this->hrbd->id],
            [
                ['item_id' => $this->betaine->id, 'percentage' => '15'],
                ['item_id' => $this->water->id, 'is_qs' => true],
            ],
            $this->plantHead->id,
        );
        $formulas->activate($this->formula->versions()->first(), $this->plantHead->id);
        $this->formula->refresh();
    }

    private function person(string $name, RoleName $role, ?Facility $at = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role->value);

        if ($at !== null) {
            EmployeeAssignment::factory()->create(['user_id' => $user->id, 'facility_id' => $at->id]);
        }

        return $user;
    }

    /**
     * A counting sheet as the store will fill it in: code, name, batch,
     * quantity, unit, mfg, expiry, rate, remarks.
     *
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function sheet(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['Item code', 'Item name', 'Batch no', 'Quantity', 'Unit', 'Mfg date', 'Expiry date', 'Rate per unit', 'Remarks'], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'launch').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'count.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Upload a filled sheet for a store and book what it matched.
     *
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function bookOpeningStockFromSheet(Warehouse $store, array $rows): void
    {
        $parsed = $this->actingAs($this->admin)
            ->post(route('stores.opening-stock.parse', $store), ['sheet' => $this->sheet($rows)])
            ->assertOk()
            ->json();

        $this->assertSame([], $parsed['problems'], 'Every row on the sheet matched a material on file.');
        $this->assertCount(count($rows), $parsed['lines']);

        $this->actingAs($this->admin)
            ->post(route('facilities.opening-stock.store', $this->rudrapur), [
                'warehouse_id' => $store->id,
                'as_of' => now()->toDateString(),
                'remarks' => 'Launch day count',
                'lines' => array_map(fn (array $l) => array_intersect_key($l, array_flip(['item_id', 'quantity', 'uom_id', 'batch_number', 'manufactured_at', 'expiry_at', 'unit_cost', 'remarks'])), $parsed['lines']),
            ])
            ->assertRedirect(route('stores.show', $store))
            ->assertSessionHasNoErrors();
    }

    private function approveQc(InventoryLot $lot, string $remarks): void
    {
        $inspection = QcInspection::query()->where('lot_id', $lot->id)->where('status', LotQcStatus::Pending->value)->sole();

        $this->actingAs($this->qc)
            ->post(route('qc.approve', $inspection), ['remarks' => $remarks, 'pin' => '2468'])
            ->assertRedirect(route('qc.show', $inspection));

        $this->assertSame(LotQcStatus::Approved, $lot->fresh()->qc_status);
    }

    private function available(Product|RawMaterial|PackagingMaterial $item, Warehouse $store, ?int $ownerClientId = null): string
    {
        return app(StockBalanceService::class)->availableForProduction($item, [$store->id], $ownerClientId)->strippedOfTrailingZeros()->__toString();
    }

    /**
     * Take a written-up consignment through invoice, IRN, signed copy and
     * out of the door.
     */
    private function sendOut(Dispatch $dispatch, string $invoiceNumber, string $irnSeed): Dispatch
    {
        $this->actingAs($this->dispatcher)->post(route('dispatches.invoice', $dispatch), [
            'invoice_number' => $invoiceNumber, 'invoice_date' => now()->toDateString(),
            'irn' => substr(hash('sha256', $irnSeed), 0, 64), 'ack_number' => '1120100365'.random_int(10000, 99999), 'ack_date' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->dispatcher)->post(route('dispatches.attachments.store', $dispatch), [
            'kind' => 'signed_invoice', 'document' => UploadedFile::fake()->create($invoiceNumber.'.pdf', 90, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->dispatcher)->post(route('dispatches.dispatch', $dispatch), [
            'transporter_name' => 'Safexpress', 'vehicle_number' => 'UK06AB1234', 'eway_bill_number' => '1234567890'.random_int(10, 99), 'eway_bill_date' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $dispatch = $dispatch->fresh();
        $this->assertSame(DispatchStatus::Dispatched, $dispatch->status);

        return $dispatch;
    }

    #[Test]
    public function launch_day_from_the_counting_sheets_to_the_marketplace(): void
    {
        // ---------------------------------------------------------------
        // 1. What is on the shelves goes in from the counting sheets.
        // ---------------------------------------------------------------
        $template = $this->actingAs($this->admin)->get(route('stores.opening-stock.template', $this->rm))->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $template->headers->get('Content-Type'));

        // The raw material store: betaine is deliberately short of what the
        // first batch needs, so the purchase side gets exercised too.
        $this->bookOpeningStockFromSheet($this->rm, [
            ['RM-0001', '', 'CAPB-OLD-1', '10', 'KG', '01/03/2026', '', '150', 'Drum A'],
            ['', 'Purified Water', 'WATER-1', '500', 'KG', '', '', '2', ''],
        ]);
        $this->bookOpeningStockFromSheet($this->pm, [
            ['PM-0001', '', 'BTL-OLD-1', '2000', 'PCS', '', '', '4.5', ''],
        ]);
        // Finished goods already made: 300 bottles of Rahat Rooh, QC passed
        // on the way in.
        $this->bookOpeningStockFromSheet($this->fg, [
            ['FG-0002', '', 'RR-OLD-01', '300', 'PCS', '01/08/2026', '31/07/2029', '85', 'From the old register'],
        ]);

        $this->assertSame('10', $this->available($this->betaine, $this->rm));
        $this->assertSame('500', $this->available($this->water, $this->rm));
        $this->assertSame('2000', $this->available($this->bottle, $this->pm));
        $this->assertSame('300', $this->available($this->rahatRooh, $this->fg));
        $this->assertSame(LotQcStatus::Approved, InventoryLot::query()->where('batch_number', 'RR-OLD-01')->sole()->qc_status, 'Opening stock is QC passed automatically.');

        // ---------------------------------------------------------------
        // 2. A third-party batch for HRBD is planned and the short material
        //    requested, delivered and released.
        // ---------------------------------------------------------------
        $this->actingAs($this->production)->post(route('plans.store'), [
            'formula_id' => $this->formula->id,
            'facility_id' => $this->rudrapur->id,
            'quantity' => '100',
            'uom_id' => $this->kg->id,
            'planned_start_date' => now()->addDay()->toDateString(),
            'manufacturing_type' => 'third_party',
            'client_id' => $this->hrbd->id,
            'client_po_ref' => 'HRBD/PO/2026/0001',
            'client_product_name' => 'Cleanse Ayurveda Face Wash',
            'required_delivery_at' => now()->addDays(14)->toDateString(),
            'material_source' => 'company',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $plan = ProductionPlan::sole();
        $this->assertSame(ProductionPlanStatus::Checked, $plan->status);
        $this->assertTrue($plan->hasShortage(), '15 KG of betaine is needed and 10 is on the shelf.');

        $this->actingAs($this->production)->post(route('plans.requests', $plan))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ProductionPlanStatus::Requested, $plan->fresh()->status);

        $request = MaterialRequest::query()->whereHas('lines', fn ($q) => $q->where('item_id', $this->betaine->id))->sole();
        $this->assertTrue($request->lines->firstWhere('item_id', $this->betaine->id)->quantity_to_order > 0);

        // The delivery is booked in against the request — keyed by the plant
        // head, since without the supplier's bill to scan only the plant head
        // may enter one by hand — and QC releases it.
        $this->actingAs($this->plantHead)->post(route('goods-receipts.store'), [
            'vendor_id' => Vendor::query()->sole()->id,
            'warehouse_id' => $this->rm->id,
            'material_request_id' => $request->id,
            'received_at' => now()->toDateString(),
            'invoice_ref' => 'CC/2026/1187',
            'post_now' => true,
            'lines' => [[
                'item_id' => $this->betaine->id, 'quantity' => '25', 'uom_id' => $this->kg->id, 'unit_price' => '152',
                'supplier_batch_ref' => 'CC-7781', 'expiry_at' => now()->addYears(2)->toDateString(),
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::query()->sole();
        $deliveredLot = InventoryLot::query()->findOrFail($receipt->lines()->sole()->lot_id);
        $this->assertSame(LotQcStatus::Pending, $deliveredLot->qc_status, 'Delivered material waits in quarantine for QC.');
        $this->assertSame('10', $this->available($this->betaine, $this->rm), 'Nothing usable until QC says so.');

        $this->approveQc($deliveredLot, 'Assay within spec, COA on file');
        $this->assertSame('35', $this->available($this->betaine, $this->rm));

        // ---------------------------------------------------------------
        // 3. The batch is made, QC'd and lands in HRBD's name.
        // ---------------------------------------------------------------
        $this->actingAs($this->production)->post(route('manufacturing.store', $plan))->assertRedirect()->assertSessionHasNoErrors();
        $order = ManufacturingOrder::sole();
        $this->assertTrue($order->isThirdParty());

        // The plant head releases the batch. A risk trigger (a client batch
        // with no margin on record) sends the release for a second signature,
        // which management gives on the Approvals screen — the batch is not
        // approved on one person's say-so.
        $this->actingAs($this->plantHead)->post(route('manufacturing.approve', $order))->assertRedirect()->assertSessionHasNoErrors();

        if ($order->fresh()->status !== ManufacturingOrderStatus::Approved) {
            $approval = Approval::query()->where('approvable_id', $order->id)->where('status', ApprovalStatus::Pending->value)->sole();

            $this->actingAs($this->management)->get(route('approvals.index'))->assertOk();
            $this->actingAs($this->management)->post(route('approvals.approve', $approval), ['comment' => 'Reviewed; release the batch.'])->assertRedirect();
        }

        $this->assertSame(ManufacturingOrderStatus::Approved, $order->fresh()->status);

        $this->actingAs($this->production)->post(route('manufacturing.start', $order))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('20', $this->available($this->betaine, $this->rm), '15 KG went into the kettle.');

        $this->actingAs($this->production)->post(route('manufacturing.complete', $order), [
            'output_quantity' => '98', 'output_units' => '980', 'manufactured_at' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(ManufacturingOrderStatus::Completed, $order->status);

        $batch = InventoryLot::query()->where('item_id', $this->cleanse->id)->sole();
        $this->assertSame($this->hrbd->id, $batch->owner_client_id, "The batch is HRBD's from the moment it exists.");
        $this->assertStringStartsWith($this->hrbd->code, (string) $batch->batch_number);
        $this->assertSame(LotQcStatus::Pending, $batch->qc_status);
        $this->assertSame('0', $this->available($this->cleanse, $this->fg, $this->hrbd->id), 'In quarantine until QC.');

        $this->approveQc($batch, 'Finished product within spec');
        $this->assertSame('980', $this->available($this->cleanse, $this->fg, $this->hrbd->id));
        $this->assertSame('0', $this->available($this->cleanse, $this->fg), "None of it counts as the company's own.");

        // ---------------------------------------------------------------
        // 4. Rahat Rooh stock moves to the Paper Market depot on a challan.
        // ---------------------------------------------------------------
        $transfers = app(StockTransferService::class);
        $ownLot = InventoryLot::query()->where('batch_number', 'RR-OLD-01')->sole();

        $transfer = $transfers->create(
            ['source_warehouse_id' => $this->fg->id, 'destination_warehouse_id' => $this->depotFg->id, 'reason' => 'Depot stock for the marketplaces'],
            [['item_id' => $this->rahatRooh->id, 'quantity' => '200', 'lot_id' => $ownLot->id]],
            $this->storekeeper->id,
        );
        $transfer = $transfers->approve($transfer, $this->plantHead->id);
        $transfer = $transfers->dispatch($transfer, $this->storekeeper->id, 'UK-06-AB-1234');

        $this->assertSame('100', $this->available($this->rahatRooh, $this->fg), '200 left the factory.');
        $this->assertSame('0', $this->available($this->rahatRooh, $this->depotFg), 'Nothing shows at the depot before the challan is scanned and booked in.');

        $this->actingAs($this->storekeeper)->get(route('transfers.challan', $transfer))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->depotKeeper)
            ->post(route('transfers.scan', $transfer), ['code' => route('transfers.show', ['transfer' => $transfer->id, 'scan' => $transfer->fresh()->challan_code])])
            ->assertRedirect(route('transfers.show', $transfer));

        $this->actingAs($this->depotKeeper)->post(route('transfers.receive', $transfer), [
            'lines' => $transfer->lines->map(fn ($l) => ['line_id' => $l->id, 'quantity' => $l->outstanding()->__toString()])->all(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(StockTransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame('200', $this->available($this->rahatRooh, $this->depotFg));

        // ---------------------------------------------------------------
        // 5. Dispatch: HRBD's goods to HRBD from the factory; Rahat Rooh to
        //    the marketplace from the depot. E-invoice first, then the gate.
        // ---------------------------------------------------------------
        $hrbdCustomer = Customer::create(['code' => 'CUS-001', 'name' => 'HRBD Herbal Life Sciences', 'legal_name' => 'HRBD Herbal Life Sciences', 'gstin' => '09AAACH5678B1Z3', 'kind' => CustomerKind::Client, 'client_id' => $this->hrbd->id, 'billing_address_line_1' => 'Sector 63', 'billing_city' => 'Noida', 'billing_pincode' => '201301', 'is_active' => true]);
        $amazon = Customer::create(['code' => 'CUS-002', 'name' => 'Amazon Seller Services', 'legal_name' => 'Amazon Seller Services Pvt Ltd', 'gstin' => '06AAACA1234C1Z8', 'kind' => CustomerKind::Marketplace, 'billing_address_line_1' => 'FC 4', 'billing_city' => 'Gurugram', 'billing_pincode' => '122015', 'is_active' => true]);

        // HRBD's batch cannot go to Amazon, whatever anyone types.
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), [
            'warehouse_id' => $this->fg->id, 'customer_id' => $amazon->id,
            'lines' => [['item_id' => $this->cleanse->id, 'lot_id' => $batch->id, 'quantity' => '10', 'unit_price' => '120']],
        ])->assertSessionHasErrors('lines');

        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), [
            'warehouse_id' => $this->fg->id, 'customer_id' => $hrbdCustomer->id, 'reference' => 'HRBD/PO/2026/0001',
            'lines' => [['item_id' => $this->cleanse->id, 'lot_id' => $batch->id, 'quantity' => '980', 'unit_price' => '120']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $toHrbd = Dispatch::query()->where('customer_id', $hrbdCustomer->id)->sole();
        $this->assertTrue($toHrbd->is_interstate, 'Uttarakhand to Uttar Pradesh: IGST.');
        $this->assertSame('117600.00', $toHrbd->taxable_value);
        $this->assertSame('21168.00', $toHrbd->igst);
        $this->assertSame('138768.00', $toHrbd->total_value);

        // Nothing leaves before the IRN is on record.
        $this->actingAs($this->dispatcher)->post(route('dispatches.dispatch', $toHrbd), [])->assertSessionHasErrors('dispatch');

        $toHrbd = $this->sendOut($toHrbd, 'HB/26-27/0001', 'hrbd');
        $this->assertSame('0', $this->available($this->cleanse, $this->fg, $this->hrbd->id), 'All 980 bottles have left.');

        $einvoice = $this->actingAs($this->dispatcher)->get(route('dispatches.einvoice', $toHrbd))->assertOk()->json()[0];
        $this->assertSame('Harbanshram Bhagwandas Ayurvedic Sansthan Private Limited', $einvoice['SellerDtls']['LglNm']);
        $this->assertSame('09AAACH5678B1Z3', $einvoice['BuyerDtls']['Gstin']);
        $this->assertSame((string) $batch->batch_number, $einvoice['ItemList'][0]['BchDtls']['Nm']);

        // From the depot, HRBD bills the marketplace for Rahat Rooh.
        $this->actingAs($this->dispatcher)->post(route('dispatches.store'), [
            'warehouse_id' => $this->depotFg->id, 'customer_id' => $amazon->id, 'reference' => 'AMZ-PO-88121',
            'lines' => [['item_id' => $this->rahatRooh->id, 'lot_id' => $ownLot->id, 'quantity' => '150', 'unit_price' => '210']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $toAmazon = Dispatch::query()->where('customer_id', $amazon->id)->sole();
        $this->assertSame($this->paperMarket->id, $toAmazon->facility_id);
        $this->assertTrue($toAmazon->is_interstate, 'Delhi to Haryana: IGST.');

        $toAmazon = $this->sendOut($toAmazon, 'HRBD/26-27/0001', 'amazon');
        $this->assertSame('50', $this->available($this->rahatRooh, $this->depotFg));

        $depotInvoice = $this->actingAs($this->dispatcher)->get(route('dispatches.einvoice', $toAmazon))->assertOk()->json()[0];
        $this->assertSame('HRBD Herbal Life Sciences', $depotInvoice['SellerDtls']['LglNm'], 'The depot bills under its own company.');
        $this->assertSame('07AAACH5678B1Z7', $depotInvoice['SellerDtls']['Gstin']);

        $this->actingAs($this->dispatcher)->get(route('dispatches.challan', $toAmazon))->assertOk()->assertHeader('content-type', 'application/pdf');

        // ---------------------------------------------------------------
        // 6. Management sees all of it.
        // ---------------------------------------------------------------
        $this->actingAs($this->management)->get(route('dashboard'))->assertOk();

        $this->actingAs($this->management)->get(route('dispatches.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.consignments', 2)
                ->where('summary.total', '175938.00'));

        $this->actingAs($this->management)->get(route('manufacturing.show', $order))->assertOk();
        $this->actingAs($this->management)->get(route('lots.trace', $batch))->assertOk();

        // The store executive who booked the deliveries cannot reach dispatch
        // or production; the dispatch manager cannot reach the recipe.
        $this->actingAs($this->storekeeper)->get(route('dispatches.index'))->assertForbidden();
        $this->actingAs($this->storekeeper)->post(route('manufacturing.approve', $order))->assertForbidden();
        // Recipes sit behind the formula lock: the dispatch manager has no
        // formula permission at all, so the screen is never reached.
        $this->assertFalse($this->dispatcher->can('formula.view'));
        $this->assertFalse($this->dispatcher->can('production.view'));
    }
}
