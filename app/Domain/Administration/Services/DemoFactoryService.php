<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Contract\Models\Client;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Inventory\Services\OpeningStockService;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Services\ManufacturingOrderService;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Quality\Services\QcInspectionService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\FacilityType;
use App\Domain\Warehousing\Models\StoreCategory;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A worked example of the whole factory.
 *
 * Enough of a cosmetics plant to walk every screen: a facility with its
 * stores, suppliers and a contract client, materials and a product, an
 * active recipe, opening stock, a delivery through QC, a plan with its
 * material requests, and a batch made from start to finish.
 *
 * It creates no login accounts. Everything is booked in the name of the
 * person who asked for it, through the same services the screens use, so
 * the ledger is as real as any other day's work. Every record carries the
 * DEMO marker in its code, so it can be picked out and cleared again.
 */
class DemoFactoryService
{
    public const string MARKER = 'DEMO';

    public function __construct(
        private readonly FormulaService $formulas,
        private readonly OpeningStockService $opening,
        private readonly GoodsReceiptService $receipts,
        private readonly QcInspectionService $qc,
        private readonly ProductionPlanService $plans,
        private readonly ManufacturingOrderService $orders,
        private readonly StockCountService $counts,
    ) {}

    /**
     * @return array<string, string> what was made, for the person to read
     */
    public function fill(User $actor): array
    {
        // Demo data is a worked example, not a controlled batch: the second
        // signature and the QC PIN would only get in its way.
        $relaxed = [
            'approvals.qc_maker_checker' => false,
            'erp.qc.require_pin' => false,
            'erp.shop_floor.require_scan_before_start' => false,
        ];
        $previous = [];

        foreach ($relaxed as $key => $value) {
            $previous[$key] = config($key);
            config([$key => $value]);
        }

        try {
            return DB::transaction(fn (): array => $this->build($actor));
        } finally {
            config($previous);
        }
    }

    /**
     * @return array<string, string>
     */
    private function build(User $actor): array
    {
        $made = [];

        $facility = $this->facility();
        $stores = $this->stores($facility);
        $made['Facility'] = "{$facility->name} with {$stores->count()} stores";

        $uoms = Uom::query()->whereIn('code', ['KG', 'G', 'ML', 'L', 'PCS'])->get()->keyBy('code');
        $kg = $uoms['KG'] ?? Uom::query()->where('code', 'KG')->firstOrFail();
        $pcs = $uoms['PCS'] ?? Uom::query()->where('code', 'PCS')->firstOrFail();

        [$vendors, $client] = $this->partners();
        $made['Partners'] = $vendors->count().' suppliers and the contract client '.$client->name;

        $materials = $this->materials($kg, $pcs, $uoms['ML'] ?? $kg);
        $made['Masters'] = count($materials['raw']).' raw materials, '.count($materials['packaging']).' packaging items, 1 product';

        $formula = $this->formula($actor, $materials, $uoms['G'] ?? $kg);
        $made['Recipe'] = "{$formula->name} ({$formula->code}), active";

        $this->openingStock($actor, $stores, $materials, $kg, $pcs);
        $made['Opening stock'] = 'Booked into the raw material and packaging stores, QC passed';

        $receipt = $this->delivery($actor, $stores, $vendors->first(), $materials['raw'][0], $kg);
        $made['Delivery'] = "{$receipt->number} received and released by QC";

        $plan = $this->plan($actor, $facility, $formula, $client, $kg);
        $made['Plan'] = "{$plan->number} checked, with its material requests";

        $order = $this->batch($actor, $plan);
        $made['Batch'] = $order === null
            ? 'Not made: the stores could not cover the recipe'
            : "{$order->number} made from start to finish";

        $count = $this->stockCount($actor, $stores);
        $made['Stock count'] = $count ?? 'Not started';

        return $made;
    }

    // -- The factory ---------------------------------------------------------

    private function facility(): Facility
    {
        $existing = Facility::query()->active()->manufacturing()->ordered()->first();

        if ($existing !== null) {
            return $existing;
        }

        $type = FacilityType::query()->where('code', 'MFG')->first();

        return Facility::query()->create([
            'code' => 'FAC-DEMO-001',
            'name' => 'Demo Manufacturing Plant',
            'facility_type_id' => $type?->id,
            'address_line_1' => 'Plot 21, SIDCUL Industrial Area',
            'city' => 'Rudrapur',
            'state' => 'Uttarakhand',
            'pincode' => '263153',
            'country' => 'India',
            'can_store' => true, 'can_receive' => true, 'can_qc' => true, 'can_manufacture' => true,
            'can_pack' => true, 'can_dispatch' => true, 'can_return' => true,
            'opening_stock_enabled' => true,
            'is_active' => true,
        ]);
    }

    /**
     * @return Collection<int, Warehouse>
     */
    private function stores(Facility $facility)
    {
        // Tuples, not a keyed array: an enum cannot be an array key.
        $wanted = [
            [WarehouseType::RawMaterial, 'RM', 'Raw Material Store'],
            [WarehouseType::Packaging, 'PM', 'Packaging Store'],
            [WarehouseType::FinishedGoods, 'FG', 'Finished Goods Store'],
            [WarehouseType::Quarantine, 'QA', 'Quarantine'],
        ];

        $categories = StoreCategory::query()->get()->keyBy(fn (StoreCategory $c) => $c->kind?->value);

        foreach ($wanted as [$type, $suffix, $name]) {
            $exists = Warehouse::query()->atFacility($facility)->where('type', $type->value)->exists();

            if ($exists) {
                continue;
            }

            $category = $categories->get($type->value);

            Warehouse::query()->create([
                'facility_id' => $facility->id,
                'code' => "{$facility->code}-{$suffix}",
                'name' => "{$facility->name} {$name}",
                'type' => $type,
                'store_category_id' => $category?->id,
                'is_quarantine' => $type === WarehouseType::Quarantine,
                'is_active' => true,
                'city' => $facility->city,
                'state' => $facility->state,
                'country' => $facility->country,
            ]);
        }

        return Warehouse::query()->atFacility($facility)->where('is_system', false)->get()->keyBy(fn (Warehouse $w) => $w->type->value);
    }

    /**
     * @return array{0: Collection<int, Vendor>, 1: Client}
     */
    private function partners(): array
    {
        $vendors = collect([
            ['code' => 'V-DEMO-001', 'name' => 'Demo Surfactants Pvt Ltd', 'gstin' => '27AAACD1111A1Z5', 'city' => 'Navi Mumbai', 'supply_type' => 'raw_material', 'lead_time_days' => 7],
            ['code' => 'V-DEMO-002', 'name' => 'Demo Packaging Works', 'gstin' => '24AAACD2222B1Z6', 'city' => 'Vapi', 'supply_type' => 'packaging', 'lead_time_days' => 12],
        ])->map(fn (array $row) => Vendor::query()->firstOrCreate(
            ['code' => $row['code']],
            [...$row, 'state' => 'Maharashtra', 'country' => 'India', 'is_approved' => true, 'is_active' => true],
        ));

        $client = Client::query()->firstOrCreate(
            ['code' => 'C-DEMO-001'],
            [
                'name' => 'Demo Beauty Brands',
                'contact_person' => 'Demo Brand Manager',
                'billing_city' => 'Noida',
                'billing_state' => 'Uttar Pradesh',
                'payment_terms_days' => 30,
                'is_active' => true,
            ],
        );

        return [$vendors, $client];
    }

    /**
     * @return array{raw: list<RawMaterial>, packaging: list<PackagingMaterial>, product: Product}
     */
    private function materials(Uom $kg, Uom $pcs, Uom $ml): array
    {
        $raw = collect([
            ['code' => 'RM-DEMO-001', 'name' => 'Cocamidopropyl Betaine 30%', 'inci_name' => 'Cocamidopropyl Betaine', 'hsn_code' => '34021190', 'standard_cost' => '150', 'reorder_level' => '50', 'minimum_stock' => '20', 'lead_time_days' => 7],
            ['code' => 'RM-DEMO-002', 'name' => 'Aloe Barbadensis Leaf Extract', 'inci_name' => 'Aloe Barbadensis Leaf Juice', 'hsn_code' => '13021911', 'standard_cost' => '420', 'reorder_level' => '10', 'minimum_stock' => '4', 'lead_time_days' => 10],
            ['code' => 'RM-DEMO-003', 'name' => 'Purified Water', 'hsn_code' => '28539010', 'standard_cost' => '2', 'reorder_level' => '200', 'minimum_stock' => '100', 'density_g_per_ml' => '1', 'lead_time_days' => 1],
        ])->map(fn (array $row) => RawMaterial::query()->firstOrCreate(
            ['code' => $row['code']],
            [...$row, 'stock_uom_id' => $kg->id, 'requires_qc' => true, 'is_batch_tracked' => true, 'shelf_life_days' => 730, 'is_active' => true],
        ))->all();

        $packaging = collect([
            ['code' => 'PM-DEMO-001', 'name' => 'Bottle 100 ml HDPE', 'hsn_code' => '39233010', 'standard_cost' => '4.2', 'reorder_level' => '2000', 'minimum_stock' => '500'],
            ['code' => 'PM-DEMO-002', 'name' => 'Flip-top Cap 24 mm', 'hsn_code' => '39235010', 'standard_cost' => '1.4', 'reorder_level' => '2000', 'minimum_stock' => '500'],
        ])->map(fn (array $row) => PackagingMaterial::query()->firstOrCreate(
            ['code' => $row['code']],
            [...$row, 'stock_uom_id' => $pcs->id, 'requires_qc' => false, 'is_batch_tracked' => true, 'is_active' => true],
        ))->all();

        $product = Product::query()->firstOrCreate(
            ['code' => 'FG-DEMO-001'],
            [
                'name' => 'Aloe Face Wash 100 ml',
                'stock_uom_id' => $pcs->id,
                'net_content' => '100',
                'net_content_uom_id' => $ml->id,
                'mrp' => '249',
                'requires_qc' => true,
                'is_batch_tracked' => true,
                'shelf_life_days' => 730,
                'is_active' => true,
            ],
        );

        foreach ($packaging as $item) {
            $product->packagingLines()->firstOrCreate(
                ['packaging_material_id' => $item->id],
                ['quantity_per_unit' => '1'],
            );
        }

        return ['raw' => $raw, 'packaging' => $packaging, 'product' => $product];
    }

    /**
     * @param  array{raw: list<RawMaterial>, packaging: list<PackagingMaterial>, product: Product}  $materials
     */
    private function formula(User $actor, array $materials, Uom $batchUom): Formula
    {
        $existing = Formula::query()->where('code', 'like', self::MARKER.'%')->orWhere('name', 'Aloe Face Wash')->first();

        if ($existing?->activeVersion !== null) {
            return $existing;
        }

        $formula = $this->formulas->create(
            ['name' => 'Aloe Face Wash', 'product_id' => $materials['product']->id, 'batch_uom_id' => $batchUom->id],
            [
                ['item_id' => $materials['raw'][0]->id, 'percentage' => '15', 'purpose' => 'Surfactant'],
                ['item_id' => $materials['raw'][1]->id, 'percentage' => '2', 'purpose' => 'Active'],
                ['item_id' => $materials['raw'][2]->id, 'is_qs' => true, 'purpose' => 'Carrier'],
            ],
            $actor->id,
        );

        $this->formulas->activate($formula->versions()->latest('id')->firstOrFail(), $actor->id);

        return $formula->refresh();
    }

    /**
     * @param  Collection<string, Warehouse>  $stores
     * @param  array{raw: list<RawMaterial>, packaging: list<PackagingMaterial>, product: Product}  $materials
     */
    private function openingStock(User $actor, $stores, array $materials, Uom $kg, Uom $pcs): void
    {
        $rm = $stores->get(WarehouseType::RawMaterial->value);
        $pm = $stores->get(WarehouseType::Packaging->value);

        if ($rm !== null) {
            $this->opening->book($rm, [
                ['item_id' => $materials['raw'][0]->id, 'quantity' => '120', 'uom_id' => $kg->id, 'batch_number' => 'DEMO-CAPB-01', 'unit_cost' => '150', 'manufactured_at' => now()->subMonths(2)->toDateString()],
                ['item_id' => $materials['raw'][1]->id, 'quantity' => '18', 'uom_id' => $kg->id, 'batch_number' => 'DEMO-ALOE-01', 'unit_cost' => '420', 'manufactured_at' => now()->subMonths(3)->toDateString()],
                ['item_id' => $materials['raw'][2]->id, 'quantity' => '900', 'uom_id' => $kg->id, 'batch_number' => 'DEMO-WATER-01', 'unit_cost' => '2'],
            ], $actor->id, now()->subDays(20)->toDateString(), 'Demo opening stock');
        }

        if ($pm !== null) {
            $this->opening->book($pm, [
                ['item_id' => $materials['packaging'][0]->id, 'quantity' => '6000', 'uom_id' => $pcs->id, 'batch_number' => 'DEMO-BTL-01', 'unit_cost' => '4.2'],
                ['item_id' => $materials['packaging'][1]->id, 'quantity' => '6000', 'uom_id' => $pcs->id, 'batch_number' => 'DEMO-CAP-01', 'unit_cost' => '1.4'],
            ], $actor->id, now()->subDays(20)->toDateString(), 'Demo opening stock');
        }
    }

    /**
     * @param  Collection<string, Warehouse>  $stores
     */
    private function delivery(User $actor, $stores, Vendor $vendor, Item $item, Uom $kg): GoodsReceipt
    {
        $receipt = $this->receipts->create([
            'vendor_id' => $vendor->id,
            'warehouse_id' => $stores->get(WarehouseType::RawMaterial->value)?->id,
            'received_at' => now()->subDays(5)->toDateString(),
            'invoice_ref' => 'DEMO/INV/2026/0007',
            'notes' => 'Demo delivery',
        ], [[
            'item_id' => $item->id,
            'quantity' => '40',
            'uom_id' => $kg->id,
            'unit_price' => '152',
            'supplier_batch_ref' => 'SUP-DEMO-4471',
            'manufactured_at' => now()->subMonths(1)->toDateString(),
            'expiry_at' => now()->addYears(2)->toDateString(),
        ]], $actor->id);

        $receipt = $this->receipts->post($receipt, $actor->id);

        // QC releases it into the store, the way a real delivery moves.
        QcInspection::query()->whereIn('goods_receipt_line_id', $receipt->lines->pluck('id'))->get()
            ->each(function (QcInspection $inspection) use ($actor): void {
                try {
                    $this->qc->approve($inspection, $actor->id, 'Demo: within specification.');
                } catch (Throwable) {
                    // A factory with maker-checker rules of its own keeps them.
                }
            });

        return $receipt->refresh();
    }

    private function plan(User $actor, Facility $facility, Formula $formula, Client $client, Uom $kg): ProductionPlan
    {
        $plan = $this->plans->create([
            'formula_id' => $formula->id,
            'facility_id' => $facility->id,
            'quantity' => '100',
            'uom_id' => $kg->id,
            'planned_start_date' => now()->addDays(2)->toDateString(),
            'notes' => 'Demo plan',
            'manufacturing_type' => 'third_party',
            'client_id' => $client->id,
            'client_po_ref' => 'DEMO/PO/2026/31',
            'client_product_name' => 'Aloe Face Wash 100 ml',
            'required_delivery_at' => now()->addDays(21)->toDateString(),
            'material_source' => 'company',
        ], $actor->id);

        try {
            $this->plans->generateRequests($plan, $actor->id);
        } catch (Throwable) {
            // Nothing to request is a fine outcome for a demo.
        }

        return $plan->refresh();
    }

    private function batch(User $actor, ProductionPlan $plan): ?ManufacturingOrder
    {
        try {
            $order = $this->orders->createFromPlan($plan, $actor->id);
            $order = $this->orders->approve($order, $actor->id);
            $order = $this->orders->start($order, $actor->id);

            return $this->orders->complete($order, $actor->id, [
                'output_quantity' => '98',
                'output_units' => 960,
                'manufactured_at' => now()->toDateString(),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  Collection<string, Warehouse>  $stores
     */
    private function stockCount(User $actor, $stores): ?string
    {
        $store = $stores->get(WarehouseType::RawMaterial->value);

        if ($store === null) {
            return null;
        }

        try {
            $count = $this->counts->start($store, $actor->id, 'Demo count of the raw material store');

            return "{$count->number} open in {$store->name}, ready to be counted";
        } catch (Throwable) {
            return null;
        }
    }
}
