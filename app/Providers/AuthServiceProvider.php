<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\Department;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\ItemPolicy;
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

        // Every flavour of item is guarded by the same policy, which decides
        // the permission module from the item's type.
        Item::class => ItemPolicy::class,
        RawMaterial::class => ItemPolicy::class,
        PackagingMaterial::class => ItemPolicy::class,
        Product::class => ItemPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->grantSuperAdminEverything();
    }

    /**
     * Super Admin passes every check.
     *
     * Returning null rather than false for everyone else is essential: false
     * would short-circuit the gate and deny the request before the real policy
     * ever ran.
     *
     * This is the only bypass in the system. It is a role, not a flag on the
     * user record, so granting and revoking it is itself an audited change to
     * that user's roles.
     */
    private function grantSuperAdminEverything(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });
    }
}
