<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Marketplace\Enums\LabelReaderKind;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\ItemCategory;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\FacilityType;
use App\Domain\Warehousing\Models\StoreCategory;
use App\Domain\Warehousing\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

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
        ['ENG', 'Engineering Store', 'ENG', 'engineering', 'wrench', 'slate', 115],
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
        self::ensureProductCategories();

        // An earlier migration runs this seeder before the online-orders
        // tables exist; that migration seeds them itself.
        if (Schema::hasTable('brands')) {
            self::ensureMarketplaces();
            self::ensureBrands();
        }
    }

    /**
     * The product categories the company sells under. The codes match the
     * ones demo data used, so a copy that already had them keeps its
     * products' categories. Any other finished-goods category that no
     * product uses is switched off, so the product screen offers these
     * three; one a product still carries stays, so nothing loses its
     * category.
     */
    public const PRODUCT_CATEGORIES = [
        ['SKIN', 'Skincare'],
        ['BODY', 'Bodycare'],
        ['HAIR', 'Haircare'],
    ];

    public static function ensureProductCategories(): void
    {
        $codes = [];

        foreach (self::PRODUCT_CATEGORIES as [$code, $name]) {
            ItemCategory::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'item_type' => ItemType::FinishedGood,
                'is_active' => true,
            ]);
            $codes[] = $code;
        }

        ItemCategory::query()
            ->where('item_type', ItemType::FinishedGood->value)
            ->whereNotIn('code', $codes)
            ->where('is_active', true)
            ->whereDoesntHave('items')
            ->update(['is_active' => false]);
    }

    /**
     * The marketplaces the company sells on, and how each one's labels are
     * read: Meesho and Flipkart print text; Amazon and Myntra send pictures.
     */
    public static function ensureMarketplaces(): void
    {
        foreach ([
            ['MEESHO', 'Meesho', LabelReaderKind::Meesho],
            ['FLIPKART', 'Flipkart', LabelReaderKind::Flipkart],
            ['AMAZON', 'Amazon', LabelReaderKind::Ai],
            ['MYNTRA', 'Myntra', LabelReaderKind::Ai],
        ] as [$code, $name, $reader]) {
            Marketplace::query()->firstOrCreate(['code' => $code], ['name' => $name, 'reader' => $reader, 'is_active' => true]);
        }
    }

    /**
     * The brands sold online. Their parcels leave from the Paper Market
     * depot's finished goods store by default, once that depot exists; a
     * default set on the Brands screen is never overwritten.
     */
    public static function ensureBrands(): void
    {
        $depotStore = self::paperMarketFinishedGoods();

        foreach ([
            ['RR', (string) config('erp.company.brand', 'Rahat Rooh')],
            ['CA', 'Cleanse Ayurveda'],
        ] as [$code, $name]) {
            $brand = Brand::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);

            if ($brand->default_warehouse_id === null && $depotStore !== null) {
                $brand->forceFill(['default_warehouse_id' => $depotStore->id])->save();
            }
        }
    }

    private static function paperMarketFinishedGoods(): ?Warehouse
    {
        $depot = Facility::query()->where('name', 'ilike', '%paper market%')->orderBy('id')->first();

        return $depot === null ? null : Warehouse::query()
            ->where('facility_id', $depot->id)
            ->where('type', WarehouseType::FinishedGoods->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
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
