<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Identity\Models\Department;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\FormulaPolicy;
use App\Policies\FormulaVersionPolicy;
use App\Policies\GoodsReceiptPolicy;
use App\Policies\InventoryLotPolicy;
use App\Policies\ManufacturingOrderPolicy;
use App\Policies\MaterialRequestPolicy;
use App\Policies\PackagingMaterialPolicy;
use App\Policies\ProductionPlanPolicy;
use App\Policies\ProductPolicy;
use App\Policies\QcInspectionPolicy;
use App\Policies\RawMaterialPolicy;
use App\Policies\UomPolicy;
use App\Policies\UserPolicy;
use App\Policies\VendorPolicy;
use App\Policies\WarehousePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Policies are registered explicitly because the ERP's models live under
     * App\Domain\<Module>\Models rather than App\Models, which is outside the
     * path Laravel's policy discovery looks in.
     *
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        User::class => UserPolicy::class,
        Department::class => DepartmentPolicy::class,
        Warehouse::class => WarehousePolicy::class,
        Vendor::class => VendorPolicy::class,
        Uom::class => UomPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        GoodsReceipt::class => GoodsReceiptPolicy::class,
        Formula::class => FormulaPolicy::class,
        FormulaVersion::class => FormulaVersionPolicy::class,
        ProductionPlan::class => ProductionPlanPolicy::class,
        MaterialRequest::class => MaterialRequestPolicy::class,
        ManufacturingOrder::class => ManufacturingOrderPolicy::class,
        QcInspection::class => QcInspectionPolicy::class,
        InventoryLot::class => InventoryLotPolicy::class,

        // Each item type has its own policy so that Laravel can resolve one
        // from the model class alone, which is all it has for class-level
        // abilities such as viewAny and create.
        RawMaterial::class => RawMaterialPolicy::class,
        PackagingMaterial::class => PackagingMaterialPolicy::class,
        Product::class => ProductPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->grantSuperAdminEverything();
    }

    /**
     * Super Admin passes every check — except one made about themselves.
     *
     * Returning null rather than false for everyone else is essential: false
     * would short-circuit the gate and deny the request before the real policy
     * ever ran.
     *
     * The self-referential exception matters. UserPolicy refuses to let anyone
     * delete their own account, deactivate themselves, or hand themselves a
     * role, and those rules exist precisely because the person most able to
     * lock the company out of its own ERP — or to quietly widen their own
     * access — is the one holding this role. A blanket bypass would waive them
     * for the only account they were written for. Acting on their own record,
     * a Super Admin goes through the ordinary policy like everybody else; they
     * still hold every permission row, so nothing legitimate is lost.
     *
     * This is the only bypass in the system. It is a role, not a flag on the
     * user record, so granting and revoking it is itself an audited change to
     * that user's roles.
     */
    private function grantSuperAdminEverything(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments = []): ?bool {
            if (! $user->isSuperAdmin()) {
                return null;
            }

            return $this->concernsOwnAccount($user, $arguments) ? null : true;
        });
    }

    /**
     * Whether this authorisation check is about the acting user's own record.
     *
     * @param  array<int, mixed>  $arguments
     */
    private function concernsOwnAccount(User $user, array $arguments): bool
    {
        $subject = $arguments[0] ?? null;

        return $subject instanceof User && $subject->is($user);
    }
}
