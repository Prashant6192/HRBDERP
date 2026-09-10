<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaSecurityService;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Manufacturing\Services\ManufacturingOrderService;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Quality\Services\QcInspectionService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A day in the life, for a development database: stock received and passed
 * by QC, a made-up recipe, a plan with its material requests, and a batch on
 * the floor. Never runs in production, and never twice.
 *
 * The recipe here is invented for the demo. Real formulations arrive through
 * Formulations → Import.
 */
class DemoOperationsSeeder extends Seeder
{
    private const string PIN = '2468';

    public function run(): void
    {
        if (app()->isProduction() || Formula::query()->exists()) {
            return;
        }

        $admin = User::query()->where('email', 'priya.raman@hrbd.local')->first();
        $store = User::query()->where('email', 'imran.sheikh@hrbd.local')->first() ?? $admin;
        $qc = User::query()->where('email', 'sunita.rao@hrbd.local')->first() ?? $admin;

        $rmStore = Warehouse::query()->where('type', WarehouseType::RawMaterial->value)->first();
        $pmStore = Warehouse::query()->where('type', WarehouseType::Packaging->value)->first();
        $product = Product::query()->where('code', 'FG-3001')->first();

        if ($admin === null || $rmStore === null || $pmStore === null || $product === null) {
            return;
        }

        $kg = Uom::query()->where('code', 'KG')->firstOrFail();
        $pcs = Uom::query()->where('code', 'PCS')->firstOrFail();
        $gram = Uom::query()->where('code', 'G')->firstOrFail();

        $sles = RawMaterial::query()->where('code', 'RM-1001')->firstOrFail();
        $capb = RawMaterial::query()->where('code', 'RM-1002')->firstOrFail();
        $aloe = RawMaterial::query()->where('code', 'RM-1003')->firstOrFail();
        $preservative = RawMaterial::query()->where('code', 'RM-1005')->firstOrFail();
        $fragrance = RawMaterial::query()->where('code', 'RM-1006')->firstOrFail();
        $glycerine = RawMaterial::query()->where('code', 'RM-1007')->firstOrFail();

        $water = RawMaterial::query()->firstOrCreate(['code' => 'RM-1009'], [
            'name' => 'Purified Water',
            'inci_name' => 'Aqua',
            'stock_uom_id' => $kg->id,
            'density_g_per_ml' => '1',
            'is_batch_tracked' => true,
            'requires_qc' => false,
            'is_active' => true,
        ]);

        // ---- Stock: a delivery, posted and passed by QC ------------------
        $receipts = app(GoodsReceiptService::class);
        $vendor = Vendor::query()->first();

        $receipt = $receipts->create([
            'vendor_id' => $vendor?->id,
            'warehouse_id' => $rmStore->id,
            'received_at' => now()->subDays(3)->toDateString(),
            'invoice_ref' => 'DEMO-INV-001',
        ], [
            ['item_id' => $sles->id, 'quantity' => '200', 'uom_id' => $kg->id, 'unit_price' => '118.50', 'supplier_batch_ref' => 'SL-4471', 'expiry_at' => now()->addMonths(18)->toDateString()],
            ['item_id' => $capb->id, 'quantity' => '120', 'uom_id' => $kg->id, 'unit_price' => '142.00', 'supplier_batch_ref' => 'CB-2210', 'expiry_at' => now()->addMonths(18)->toDateString()],
            ['item_id' => $aloe->id, 'quantity' => '40', 'uom_id' => $kg->id, 'unit_price' => '218.00', 'supplier_batch_ref' => 'AL-0093', 'expiry_at' => now()->addDays(45)->toDateString()],
            ['item_id' => $preservative->id, 'quantity' => '25', 'uom_id' => $kg->id, 'unit_price' => '385.00', 'supplier_batch_ref' => 'PH-771', 'expiry_at' => now()->addYears(2)->toDateString()],
            ['item_id' => $glycerine->id, 'quantity' => '60', 'uom_id' => $kg->id, 'unit_price' => '96.00', 'supplier_batch_ref' => 'GL-1502', 'expiry_at' => now()->addYears(2)->toDateString()],
            ['item_id' => $fragrance->id, 'quantity' => '4', 'uom_id' => $kg->id, 'unit_price' => '2150.00', 'supplier_batch_ref' => 'FR-OB-31', 'expiry_at' => now()->addYear()->toDateString()],
            ['item_id' => $water->id, 'quantity' => '2000', 'uom_id' => $kg->id],
        ], $store->id);
        $receipts->post($receipt, $store->id);

        $inspections = app(QcInspectionService::class);

        foreach (QcInspection::query()->open()->get() as $inspection) {
            $inspections->approve($inspection, $qc->id, 'Demo: passed on receipt');
        }

        // Packaging, straight to its store (no QC on demo packaging).
        $packaging = PackagingMaterial::query()->where('is_active', true)->orderBy('code')->take(3)->get();

        if ($packaging->isNotEmpty()) {
            $pmReceipt = $receipts->create([
                'vendor_id' => $vendor?->id,
                'warehouse_id' => $pmStore->id,
                'received_at' => now()->subDays(2)->toDateString(),
                'invoice_ref' => 'DEMO-INV-002',
            ], $packaging->map(fn (PackagingMaterial $p) => [
                'item_id' => $p->id, 'quantity' => '5000', 'uom_id' => $pcs->id, 'unit_price' => '4.50',
            ])->all(), $store->id);
            $receipts->post($pmReceipt, $store->id);

            foreach (QcInspection::query()->open()->get() as $inspection) {
                $inspections->approve($inspection, $qc->id, 'Demo: passed on receipt');
            }

            foreach ($packaging as $index => $p) {
                $product->packagingLines()->firstOrCreate(
                    ['packaging_material_id' => $p->id],
                    ['quantity_per_unit' => $index === 2 ? '0.02' : '1'],
                );
            }
        }

        // ---- A recipe for the shampoo (invented, for the demo only) ------
        $formulas = app(FormulaService::class);

        $formula = $formulas->create([
            'name' => $product->name,
            'product_id' => $product->id,
            'batch_uom_id' => $gram->id,
            'description' => 'Demo recipe — not a real formulation.',
        ], [
            ['item_id' => $water->id, 'is_qs' => true, 'qs_note' => 'QS to 100 g', 'grade' => 'IP', 'purpose' => 'Solvent'],
            ['item_id' => $sles->id, 'percentage' => '12', 'grade' => 'IH', 'purpose' => 'Cleaning'],
            ['item_id' => $capb->id, 'percentage' => '6', 'grade' => 'IH', 'purpose' => 'Cleaning'],
            ['item_id' => $glycerine->id, 'percentage' => '3', 'grade' => 'IP', 'purpose' => 'Humectant'],
            ['item_id' => $aloe->id, 'percentage' => '2', 'grade' => 'IH', 'purpose' => 'Skin conditioning'],
            ['item_id' => $preservative->id, 'percentage' => '0.8', 'grade' => 'IP', 'purpose' => 'Preservative'],
            ['item_id' => $fragrance->id, 'percentage' => '0.5', 'grade' => 'IH', 'purpose' => 'Perfuming'],
        ], $admin->id);
        $formulas->activate($formula->versions()->first(), $admin->id);

        app(FormulaSecurityService::class)->setPin($admin, self::PIN);

        // ---- A plan with its requests, and a batch on the floor ---------
        $plans = app(ProductionPlanService::class);

        $shortPlan = $plans->create([
            'formula_id' => $formula->id,
            'quantity' => '500',
            'uom_id' => $kg->id,
            'planned_start_date' => now()->addDays(10)->toDateString(),
            'notes' => 'Demo: a batch the stores cannot yet cover.',
        ], $admin->id);
        $plans->generateRequests($shortPlan, $admin->id);

        $runningPlan = $plans->create([
            'formula_id' => $formula->id,
            'quantity' => '100',
            'uom_id' => $kg->id,
            'planned_start_date' => now()->toDateString(),
        ], $admin->id);

        $orders = app(ManufacturingOrderService::class);
        $order = $orders->createFromPlan($runningPlan, $admin->id, 'Demo batch.');
        $order = $orders->approve($order, $admin->id);
        $orders->start($order, $admin->id);

        $this->command?->info('Demo operations seeded. Formula PIN for priya.raman@hrbd.local: "'.self::PIN.'".');
    }
}
