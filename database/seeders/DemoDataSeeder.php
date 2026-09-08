<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Department;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\ItemCategory;
use App\Domain\MasterData\Models\ItemUomConversion;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Populates a recognisable, working example of the business.
 *
 * This is development and demonstration data. It is never seeded in
 * production — DatabaseSeeder refuses — because it creates accounts with a
 * known password.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * The shared password for every demo account. Development only.
     */
    private const DEMO_PASSWORD = 'password';

    /**
     * @var array<string, array{role: RoleName, department: string}>
     */
    private const DEMO_USERS = [
        'Priya Raman' => ['role' => RoleName::SuperAdmin, 'department' => 'ADMIN'],
        'Anil Kapoor' => ['role' => RoleName::Owner, 'department' => 'MGMT'],
        'Meera Shah' => ['role' => RoleName::Director, 'department' => 'MGMT'],
        'Rohit Verma' => ['role' => RoleName::Management, 'department' => 'MGMT'],
        'Suresh Patil' => ['role' => RoleName::FactoryManager, 'department' => 'PROD'],
        'Kavita Joshi' => ['role' => RoleName::ProductionManager, 'department' => 'PROD'],
        'Imran Sheikh' => ['role' => RoleName::WarehouseManager, 'department' => 'WH'],
        'Deepak Nair' => ['role' => RoleName::PurchaseManager, 'department' => 'PUR'],
        'Sunita Rao' => ['role' => RoleName::QcManager, 'department' => 'QC'],
        'Vikram Desai' => ['role' => RoleName::AccountsManager, 'department' => 'ACCT'],
        'Neha Gupta' => ['role' => RoleName::MarketingManager, 'department' => 'MKTG'],
        'Arjun Menon' => ['role' => RoleName::EcommerceManager, 'department' => 'ECOM'],
        'Farah Khan' => ['role' => RoleName::SalesManager, 'department' => 'SALES'],
        'Sanjay Bose' => ['role' => RoleName::BrandManager, 'department' => 'MKTG'],
        'Ritu Malhotra' => ['role' => RoleName::Designer, 'department' => 'DSGN'],
        'Ganesh Iyer' => ['role' => RoleName::Viewer, 'department' => 'ADMIN'],
    ];

    public function run(): void
    {
        $this->seedUsers();
        $this->seedWarehouses();
        $this->seedCategories();
        $this->seedMaterials();
        $this->seedVendors();
    }

    private function seedUsers(): void
    {
        $departments = Department::pluck('id', 'code');
        $sequence = 1;

        foreach (self::DEMO_USERS as $name => $definition) {
            $email = $this->emailFor($name);

            $user = User::withTrashed()->updateOrCreate(
                ['email' => $email],
                [
                    'employee_code' => sprintf('EMP-%03d', $sequence++),
                    'name' => $name,
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'department_id' => $departments[$definition['department']] ?? null,
                    'designation' => $definition['role']->value,
                    'phone' => '9'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                    'deleted_at' => null,
                ],
            );

            $user->syncRoles([$definition['role']->value]);

            // The two roles that may read formulations get a demo PIN so the
            // second-factor flow can be tried out immediately.
            if (in_array($definition['role'], [RoleName::SuperAdmin, RoleName::FactoryManager], strict: true)) {
                $user->setFormulaPin('135790');
            }
        }
    }

    private function emailFor(string $name): string
    {
        return str_replace(' ', '.', strtolower($name)).'@hrbd.local';
    }

    private function seedWarehouses(): void
    {
        $warehouses = [
            ['code' => 'WH-RM', 'name' => 'Raw Material Store', 'type' => WarehouseType::RawMaterial],
            ['code' => 'WH-PM', 'name' => 'Packaging Store', 'type' => WarehouseType::Packaging],
            ['code' => 'WH-FG', 'name' => 'Finished Goods Store', 'type' => WarehouseType::FinishedGoods],
            ['code' => 'WH-QA', 'name' => 'Quarantine Store', 'type' => WarehouseType::Quarantine],
            ['code' => 'WH-MP', 'name' => 'Marketplace Fulfilment', 'type' => WarehouseType::Marketplace],
        ];

        foreach ($warehouses as $definition) {
            $warehouse = Warehouse::updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'is_quarantine' => $definition['type']->holdsQuarantinedStock(),
                    'address_line_1' => 'Plot 14, MIDC Industrial Area',
                    'city' => 'Pune',
                    'state' => 'Maharashtra',
                    'pincode' => '411018',
                    'country' => 'India',
                    'is_active' => true,
                ],
            );

            foreach (['A-01', 'A-02', 'B-01', 'STAGE'] as $code) {
                $warehouse->locations()->updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $code === 'STAGE' ? 'Staging Area' : "Rack {$code}",
                        'type' => $code === 'STAGE' ? 'staging' : 'rack',
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    private function seedCategories(): void
    {
        $categories = [
            ['code' => 'SURF', 'name' => 'Surfactants', 'type' => ItemType::RawMaterial],
            ['code' => 'ACTV', 'name' => 'Actives', 'type' => ItemType::RawMaterial],
            ['code' => 'PRES', 'name' => 'Preservatives', 'type' => ItemType::RawMaterial],
            ['code' => 'FRAG', 'name' => 'Fragrances', 'type' => ItemType::RawMaterial],
            ['code' => 'BOTL', 'name' => 'Bottles', 'type' => ItemType::PackagingMaterial],
            ['code' => 'CAPS', 'name' => 'Caps & Closures', 'type' => ItemType::PackagingMaterial],
            ['code' => 'LABL', 'name' => 'Labels & Cartons', 'type' => ItemType::PackagingMaterial],
            ['code' => 'HAIR', 'name' => 'Hair Care', 'type' => ItemType::FinishedGood],
            ['code' => 'SKIN', 'name' => 'Skin Care', 'type' => ItemType::FinishedGood],
        ];

        foreach ($categories as $definition) {
            ItemCategory::updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'item_type' => $definition['type'],
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedMaterials(): void
    {
        $categories = ItemCategory::pluck('id', 'code');
        $uoms = Uom::pluck('id', 'code');

        $rawMaterials = [
            ['code' => 'RM-1001', 'name' => 'Sodium Laureth Sulphate 70%', 'category' => 'SURF', 'uom' => 'KG', 'cost' => '118.5000', 'density' => '1.050000'],
            ['code' => 'RM-1002', 'name' => 'Cocamidopropyl Betaine 30%', 'category' => 'SURF', 'uom' => 'KG', 'cost' => '142.0000', 'density' => '1.040000'],
            ['code' => 'RM-1003', 'name' => 'Aloe Vera Extract', 'category' => 'ACTV', 'uom' => 'KG', 'cost' => '218.0000', 'density' => '1.010000'],
            ['code' => 'RM-1004', 'name' => 'Vitamin E Acetate', 'category' => 'ACTV', 'uom' => 'KG', 'cost' => '1450.0000', 'density' => null],
            ['code' => 'RM-1005', 'name' => 'Phenoxyethanol', 'category' => 'PRES', 'uom' => 'KG', 'cost' => '385.0000', 'density' => '1.102000'],
            ['code' => 'RM-1006', 'name' => 'Fragrance — Ocean Breeze', 'category' => 'FRAG', 'uom' => 'KG', 'cost' => '2150.0000', 'density' => '0.960000'],
            ['code' => 'RM-1007', 'name' => 'Glycerine USP', 'category' => 'ACTV', 'uom' => 'KG', 'cost' => '96.0000', 'density' => '1.260000'],
            ['code' => 'RM-1008', 'name' => 'Citric Acid Monohydrate', 'category' => 'ACTV', 'uom' => 'KG', 'cost' => '78.0000', 'density' => null],
        ];

        foreach ($rawMaterials as $definition) {
            RawMaterial::updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'category_id' => $categories[$definition['category']] ?? null,
                    'stock_uom_id' => $uoms['KG'],
                    'density_g_per_ml' => $definition['density'],
                    'standard_cost' => $definition['cost'],
                    'hsn_code' => '34021900',
                    'gst_rate' => '18.000',
                    'is_batch_tracked' => true,
                    'requires_qc' => true,
                    'shelf_life_days' => 730,
                    'reorder_level' => '50.000000',
                    'minimum_stock' => '25.000000',
                    'is_active' => true,
                ],
            );
        }

        $packaging = [
            ['code' => 'PM-2001', 'name' => '200ml HDPE Bottle — White', 'category' => 'BOTL', 'cost' => '8.4000', 'perCarton' => '120'],
            ['code' => 'PM-2002', 'name' => '100ml PET Bottle — Clear', 'category' => 'BOTL', 'cost' => '5.9000', 'perCarton' => '200'],
            ['code' => 'PM-2003', 'name' => 'Flip-Top Cap 24mm — White', 'category' => 'CAPS', 'cost' => '2.1000', 'perCarton' => '1000'],
            ['code' => 'PM-2004', 'name' => 'Pump Dispenser 28mm', 'category' => 'CAPS', 'cost' => '6.7000', 'perCarton' => '500'],
            ['code' => 'PM-2005', 'name' => 'Shrink Sleeve Label — Hydra Smooth', 'category' => 'LABL', 'cost' => '1.4000', 'perCarton' => '2000'],
            ['code' => 'PM-2006', 'name' => 'Mono Carton — Hydra Smooth 200ml', 'category' => 'LABL', 'cost' => '4.2000', 'perCarton' => '250'],
        ];

        foreach ($packaging as $definition) {
            $material = PackagingMaterial::updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'category_id' => $categories[$definition['category']] ?? null,
                    'stock_uom_id' => $uoms['PCS'],
                    'standard_cost' => $definition['cost'],
                    'hsn_code' => '39235010',
                    'gst_rate' => '18.000',
                    'is_batch_tracked' => false,
                    'requires_qc' => false,
                    'reorder_level' => '2000.000000',
                    'is_active' => true,
                ],
            );

            // Packaging arrives by the carton, and how many a carton holds is
            // a fact about this component rather than about cartons.
            ItemUomConversion::updateOrCreate(
                [
                    'item_id' => $material->getKey(),
                    'from_uom_id' => $uoms['CTN'],
                    'to_uom_id' => $uoms['PCS'],
                ],
                ['factor' => $definition['perCarton']],
            );
        }

        $products = [
            ['code' => 'FG-3001', 'name' => 'Hydra Smooth Shampoo 200ml', 'category' => 'HAIR', 'content' => '200', 'mrp' => '349.0000'],
            ['code' => 'FG-3002', 'name' => 'Argan Repair Conditioner 200ml', 'category' => 'HAIR', 'content' => '200', 'mrp' => '399.0000'],
            ['code' => 'FG-3003', 'name' => 'Vitamin C Face Serum 30ml', 'category' => 'SKIN', 'content' => '30', 'mrp' => '699.0000'],
            ['code' => 'FG-3004', 'name' => 'Aloe Soothing Gel 100ml', 'category' => 'SKIN', 'content' => '100', 'mrp' => '249.0000'],
        ];

        foreach ($products as $definition) {
            Product::updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'category_id' => $categories[$definition['category']] ?? null,
                    'brand' => 'HRBD',
                    'stock_uom_id' => $uoms['PCS'],
                    'net_content' => $definition['content'],
                    'net_content_uom_id' => $uoms['ML'],
                    'mrp' => $definition['mrp'],
                    'hsn_code' => '33051010',
                    'gst_rate' => '18.000',
                    'is_batch_tracked' => true,
                    'requires_qc' => true,
                    'shelf_life_days' => 1095,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedVendors(): void
    {
        $vendors = [
            ['code' => 'VEN-1001', 'name' => 'Galaxy Surfactants Ltd', 'supply' => 'raw_material', 'terms' => 30],
            ['code' => 'VEN-1002', 'name' => 'Kumar Organics Pvt Ltd', 'supply' => 'raw_material', 'terms' => 45],
            ['code' => 'VEN-1003', 'name' => 'Pearl Polymers Ltd', 'supply' => 'packaging', 'terms' => 30],
            ['code' => 'VEN-1004', 'name' => 'Shakti Print & Pack', 'supply' => 'packaging', 'terms' => 15],
            ['code' => 'VEN-1005', 'name' => 'Aromatics International', 'supply' => 'raw_material', 'terms' => 60],
        ];

        foreach ($vendors as $index => $definition) {
            Vendor::updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'legal_name' => $definition['name'],
                    'gstin' => sprintf('27AABCU%04dK1Z%d', 9000 + $index, $index),
                    'contact_person' => 'Sales Desk',
                    'email' => 'sales@'.str_replace(' ', '', strtolower($definition['name'])).'.example',
                    'phone' => '9820'.str_pad((string) (100000 + $index), 6, '0', STR_PAD_LEFT),
                    'city' => 'Mumbai',
                    'state' => 'Maharashtra',
                    'pincode' => '400001',
                    'country' => 'India',
                    'payment_terms_days' => $definition['terms'],
                    'supply_type' => $definition['supply'],
                    'is_approved' => true,
                    'is_active' => true,
                ],
            );
        }
    }
}
