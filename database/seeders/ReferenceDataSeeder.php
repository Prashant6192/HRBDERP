<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\FacilityType;
use App\Domain\Warehousing\Models\StoreCategory;
use App\Domain\Warehousing\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * The facility types and store categories the ERP ships with.
 *
 * Safe in every environment and safe to re-run: rows are matched by code
 * and only created when missing, so renames and deactivations made on the
 * screens survive a re-seed.
 */
class ReferenceDataSeeder extends Seeder
{
    /**
     * @var list<array{0: string, 1: string, 2: string|null, 3: array<string, bool>, 4: int}>
     */
    public const array FACILITY_TYPES = [
        ['MFG', 'Manufacturing Facility', 'A plant that makes product: stores, receiving, QC, manufacturing, packaging and dispatch.', ['can_store' => true, 'can_receive' => true, 'can_qc' => true, 'can_manufacture' => true, 'can_pack' => true, 'can_dispatch' => true, 'can_return' => true], 10],
        ['WH', 'Warehouse / Storage', 'Holds stock and ships it; no production.', ['can_store' => true, 'can_receive' => true, 'can_qc' => false, 'can_manufacture' => false, 'can_pack' => false, 'can_dispatch' => true, 'can_return' => true], 20],
        ['DC', 'Distribution Centre', 'Receives finished goods and dispatches to customers.', ['can_store' => true, 'can_receive' => true, 'can_qc' => false, 'can_manufacture' => false, 'can_pack' => false, 'can_dispatch' => true, 'can_return' => true], 30],
        ['OFFICE', 'Office', 'No stock.', ['can_store' => false, 'can_receive' => false, 'can_qc' => false, 'can_manufacture' => false, 'can_pack' => false, 'can_dispatch' => false, 'can_return' => false], 40],
        ['3PL', 'Third Party Warehouse', "Stock held by a logistics partner on the company's behalf.", ['can_store' => true, 'can_receive' => true, 'can_qc' => false, 'can_manufacture' => false, 'can_pack' => false, 'can_dispatch' => true, 'can_return' => true], 50],
        ['MKT', 'Marketplace Warehouse', 'Amazon, Flipkart and similar fulfilment centres.', ['can_store' => true, 'can_receive' => true, 'can_qc' => false, 'can_manufacture' => false, 'can_pack' => false, 'can_dispatch' => true, 'can_return' => true], 60],
        ['DEPOT', 'Depot', 'A small forward stock point.', ['can_store' => true, 'can_receive' => true, 'can_qc' => false, 'can_manufacture' => false, 'can_pack' => false, 'can_dispatch' => true, 'can_return' => false], 70],
        ['OTHER', 'Other', null, ['can_store' => true, 'can_receive' => true, 'can_qc' => false, 'can_manufacture' => false, 'can_pack' => false, 'can_dispatch' => false, 'can_return' => false], 90],
    ];

    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int}>
     */
    public const array STORE_CATEGORIES = [
        ['RM', 'Raw Material', 'RM', 'raw_material', 'flask-conical', 'sky', 10],
        ['PM', 'Packaging Material', 'PM', 'packaging', 'package', 'violet', 20],
        ['FG', 'Finished Goods', 'FG', 'finished_goods', 'boxes', 'emerald', 30],
        ['QUAR', 'Quarantine', 'QUAR', 'quarantine', 'shield-alert', 'amber', 40],
        ['REJ', 'Rejected', 'REJ', 'rejected', 'ban', 'red', 50],
        ['PROD', 'Production Staging', 'PROD', 'production_staging', 'factory', 'orange', 60],
        ['PACK', 'Packaging Staging', 'PACK', 'packaging_staging', 'package-check', 'orange', 70],
        ['SMPL', 'Samples', 'SMPL', 'samples', 'test-tube', 'teal', 80],
        ['RET', 'Returns', 'RET', 'returns', 'undo', 'slate', 90],
        ['DMG', 'Damaged Goods', 'DMG', 'damaged', 'alert-triangle', 'red', 100],
        ['MKT', 'Marketplace', 'MKT', 'marketplace', 'store', 'indigo', 110],
        ['GEN', 'General', 'GEN', 'general', 'warehouse', 'slate', 120],
        ['TRANSIT', 'In Transit', 'TRANSIT', 'in_transit', 'truck', 'slate', 130],
    ];

    public function run(): void
    {
        foreach (self::FACILITY_TYPES as [$code, $name, $description, $capabilities, $order]) {
            FacilityType::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'description' => $description,
                'default_capabilities' => $capabilities,
                'is_system' => true,
                'is_active' => true,
                'sort_order' => $order,
            ]);
        }

        foreach (self::STORE_CATEGORIES as [$code, $name, $badge, $kind, $icon, $color, $order]) {
            StoreCategory::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'badge' => $badge,
                'kind' => $kind,
                'icon' => $icon,
                'color' => $color,
                'is_system' => true,
                'is_active' => true,
                'sort_order' => $order,
            ]);
        }

        self::ensureTransitStore();
    }

    /**
     * The system's in-transit position: stock on a lorry between facilities
     * belongs to no store and no facility, but must still be on the books.
     */
    public static function ensureTransitStore(): Warehouse
    {
        $category = StoreCategory::query()->where('kind', WarehouseType::InTransit->value)->first();

        return Warehouse::withTrashed()->firstOrCreate(['code' => 'SYS-TRANSIT'], [
            'name' => 'In Transit',
            'type' => WarehouseType::InTransit,
            'store_category_id' => $category?->id,
            'is_quarantine' => true,
            'is_active' => true,
            'is_system' => true,
            'sort_order' => 999,
            'country' => 'India',
        ]);
    }
}
