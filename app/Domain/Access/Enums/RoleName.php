<?php

declare(strict_types=1);

namespace App\Domain\Access\Enums;

use App\Domain\Access\PermissionCatalogue;

/**
 * The roles shipped with the ERP, and the permissions each one carries.
 *
 * These are the starting positions, not a straitjacket: an administrator can
 * edit any role's permissions in the Roles screen, and can create new roles.
 * Re-running the role seeder restores the defaults defined here.
 *
 * Super Admin is deliberately absent from the permission mapping — it is
 * granted everything by a Gate::before hook (see AuthServiceProvider) rather
 * than by holding thousands of individual permission rows.
 */
enum RoleName: string
{
    case SuperAdmin = 'Super Admin';
    case Owner = 'Owner';
    case Director = 'Director';
    case Management = 'Management';
    case FactoryManager = 'Factory Manager';
    case ProductionManager = 'Production Manager';
    case WarehouseManager = 'Warehouse Manager';
    case PurchaseManager = 'Purchase Manager';
    case QcManager = 'QC Manager';
    case AccountsManager = 'Accounts Manager';
    case MarketingManager = 'Marketing Manager';
    case EcommerceManager = 'E-commerce Manager';
    case SalesManager = 'Sales Manager';
    case BrandManager = 'Brand Manager';
    case Designer = 'Designer';
    case Viewer = 'Viewer';

    /**
     * Whether this role bypasses individual permission checks entirely.
     */
    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * A short description shown in the role editor.
     */
    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Unrestricted access, including roles, permissions and settings. Reserved for the system administrator.',
            self::Owner => 'Full visibility of the business including formulations, costing and every report.',
            self::Director => 'Board-level oversight with approval authority across production, procurement and formulations.',
            self::Management => 'Cross-department visibility and approval authority, without administration rights.',
            self::FactoryManager => 'Runs the plant: production, inventory, quality and the formulations needed to manufacture.',
            self::ProductionManager => 'Creates and runs manufacturing orders and consumes materials against them.',
            self::WarehouseManager => 'Receives, adjusts and transfers stock, and maintains warehouse master data.',
            self::PurchaseManager => 'Raises and approves purchase orders and maintains vendors and prices.',
            self::QcManager => 'Approves or rejects material and batch quality, and holds stock from release.',
            self::AccountsManager => 'Costing, pricing and financial reporting.',
            self::MarketingManager => 'Brand, product presentation and marketing reporting.',
            self::EcommerceManager => 'Marketplace listings, imports and reconciliation.',
            self::SalesManager => 'Sales orders, customers and sales reporting.',
            self::BrandManager => 'Product master and brand-level reporting.',
            self::Designer => 'Packaging artwork and product imagery. No access to formulations or costing.',
            self::Viewer => 'Read-only access to operational data. No formulations.',
        };
    }

    /**
     * The permissions granted to this role by default.
     *
     * Patterns ending in ".*" expand to every ability of that module, so a
     * role keeps pace with abilities added to a module later.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return PermissionCatalogue::expand($this->permissionPatterns());
    }

    /**
     * @return list<string>
     */
    private function permissionPatterns(): array
    {
        return match ($this) {

            // Granted everything through Gate::before; listed for completeness
            // so that the Roles screen shows the role as all-encompassing.
            self::SuperAdmin => ['*'],

            self::Owner => ['*'],

            self::Director => [
                'user.view', 'audit.view', 'audit.export', 'department.view',
                'facility.view', 'warehouse.view', 'product.view', 'raw_material.view',
                'packaging_material.view', 'vendor.view', 'uom.view',
                'inventory.view', 'inventory.approve_transfer', 'inventory.export',
                'formula.view', 'formula.approve', 'formula.export',
                'planning.view', 'planning.approve', 'planning.cancel', 'planning.export',
                'production.view', 'production.approve', 'production.cancel', 'production.export',
                'purchase.view', 'purchase.approve', 'purchase.export',
                'qc.view', 'sales.view', 'sales.export',
                'marketplace.view', 'costing.view', 'costing.export',
                'report.view', 'report.export',
                'approval.view', 'approval.act',
            ],

            self::Management => [
                'user.view', 'department.view', 'audit.view',
                'facility.view', 'warehouse.view', 'product.view', 'raw_material.view',
                'packaging_material.view', 'vendor.view', 'uom.view',
                'inventory.view', 'inventory.approve_transfer', 'inventory.export',
                'formula.view',
                'planning.view', 'planning.approve',
                'production.view', 'production.approve', 'production.export',
                'purchase.view', 'purchase.approve', 'purchase.export',
                'qc.view', 'sales.view', 'marketplace.view',
                'costing.view', 'report.view', 'report.export',
                'approval.view', 'approval.act',
            ],

            self::FactoryManager => [
                'facility.view', 'warehouse.view', 'product.view', 'raw_material.view',
                'packaging_material.view', 'vendor.view', 'uom.view',
                'inventory.*',
                'formula.view', 'formula.create', 'formula.edit', 'formula.import', 'formula.export',
                'planning.*',
                'production.*',
                'purchase.view', 'purchase.create',
                'qc.view', 'qc.create', 'qc.approve', 'qc.reject',
                'costing.view', 'report.view', 'report.export',
                'approval.view', 'approval.act',
            ],

            self::ProductionManager => [
                'facility.view', 'warehouse.view', 'product.view', 'raw_material.view',
                'packaging_material.view', 'uom.view',
                'inventory.view', 'inventory.reserve', 'inventory.consume',
                'formula.view',
                'planning.view', 'planning.create', 'planning.edit', 'planning.export',
                'production.view', 'production.create', 'production.edit', 'production.consume', 'production.export',
                'qc.view', 'report.view',
                'approval.view',
            ],

            self::WarehouseManager => [
                'facility.view', 'warehouse.*', 'product.view', 'raw_material.view',
                'packaging_material.view', 'uom.view',
                'inventory.view', 'inventory.receive', 'inventory.adjust',
                'inventory.transfer', 'inventory.receive_transfer',
                'inventory.opening_stock', 'inventory.export',
                'purchase.view', 'purchase.receive',
                'planning.view', 'production.view', 'qc.view',
                'report.view', 'report.export',
                'approval.view',
            ],

            self::PurchaseManager => [
                'vendor.*',

                // Procurement owns the material masters: they are the ones who
                // onboard a new material or component when they source it.
                // Deletion is deliberately withheld — a material with purchase
                // history is deactivated, not removed.
                'raw_material.view', 'raw_material.create', 'raw_material.edit',
                'raw_material.export', 'raw_material.import',
                'packaging_material.view', 'packaging_material.create',
                'packaging_material.edit', 'packaging_material.export',
                'packaging_material.import',

                'product.view', 'facility.view', 'warehouse.view', 'uom.view',
                'inventory.view',
                'planning.view', 'planning.export',
                'purchase.*',
                'costing.view',
                'report.view', 'report.export',
                'approval.view', 'approval.act',
            ],

            self::QcManager => [
                'facility.view', 'warehouse.view', 'product.view', 'raw_material.view',
                'packaging_material.view', 'vendor.view', 'uom.view',
                'inventory.view',
                'formula.view',
                'production.view',
                'purchase.view',
                'qc.*',
                'report.view', 'report.export',
                'approval.view', 'approval.act',
            ],

            self::AccountsManager => [
                'vendor.view', 'product.view', 'raw_material.view',
                'packaging_material.view', 'facility.view', 'warehouse.view', 'uom.view',
                'inventory.view', 'inventory.export',
                'production.view',
                'purchase.view', 'purchase.export',
                'sales.view', 'sales.export',
                'marketplace.view',
                'costing.*',
                'report.view', 'report.export',
                'approval.view',
            ],

            self::MarketingManager => [
                'product.view', 'product.edit',
                'sales.view', 'sales.export',
                'marketplace.view',
                'report.view', 'report.export',
            ],

            self::EcommerceManager => [
                'product.view',
                'inventory.view',
                'sales.view', 'sales.create', 'sales.edit', 'sales.export',
                'marketplace.*',
                'report.view', 'report.export',
            ],

            self::SalesManager => [
                'product.view',
                'inventory.view',
                'sales.*',
                'marketplace.view',
                'report.view', 'report.export',
            ],

            self::BrandManager => [
                'product.view', 'product.create', 'product.edit', 'product.export',
                'packaging_material.view',
                'sales.view', 'marketplace.view',
                'report.view',
            ],

            self::Designer => [
                'product.view',
                'packaging_material.view',
            ],

            self::Viewer => [
                'facility.view', 'warehouse.view', 'product.view', 'raw_material.view',
                'packaging_material.view', 'vendor.view', 'uom.view',
                'inventory.view', 'production.view', 'purchase.view',
                'qc.view', 'sales.view', 'report.view',
            ],
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
