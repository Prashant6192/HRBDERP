<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The landing screen.
 *
 * Every figure on it is behind the permission that governs the module it comes
 * from, so two people signing in see different dashboards — and neither of
 * them learns a count they are not entitled to.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('dashboard', [
            'stats' => array_values(array_filter([
                $this->stat($user, 'raw_material.view', 'Raw Materials', fn () => RawMaterial::active()->count(), 'flask-conical', 'raw-materials.index'),
                $this->stat($user, 'packaging_material.view', 'Packaging Materials', fn () => PackagingMaterial::active()->count(), 'package', 'packaging-materials.index'),
                $this->stat($user, 'product.view', 'Finished Goods', fn () => Product::active()->count(), 'boxes', 'products.index'),
                $this->stat($user, 'warehouse.view', 'Warehouses', fn () => Warehouse::active()->count(), 'warehouse', 'warehouses.index'),
                $this->stat($user, 'vendor.view', 'Active Vendors', fn () => Vendor::active()->count(), 'truck', 'vendors.index'),
                $this->stat($user, 'user.view', 'Employees', fn () => User::active()->count(), 'users', 'users.index'),
            ])),

            'recentActivity' => $user->can('audit.view')
                ? AuditLog::query()
                    ->latest('created_at')
                    ->limit(8)
                    ->get(['id', 'user_name', 'action', 'auditable_label', 'auditable_type', 'created_at'])
                : [],

            'canViewAudit' => $user->can('audit.view'),
        ]);
    }

    /**
     * Build a dashboard tile, or nothing at all if the user may not see it.
     *
     * The count is a closure so that a query is never run for a figure that is
     * about to be filtered out.
     *
     * @return array{label: string, value: int, icon: string, route: string}|null
     */
    private function stat(User $user, string $permission, string $label, callable $count, string $icon, string $route): ?array
    {
        if (! $user->can($permission)) {
            return null;
        }

        return [
            'label' => $label,
            'value' => $count(),
            'icon' => $icon,
            'route' => $route,
        ];
    }
}
