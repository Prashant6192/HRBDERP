<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Domain\Procurement\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StoreVendorRequest;
use App\Http\Requests\Procurement\UpdateVendorRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE = ['code', 'name', 'city', 'payment_terms_days', 'is_active', 'created_at'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vendor::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['supply_type', 'status']);

        $query = Vendor::query()->search($table->search);

        if ($supplyType = $table->filter('supply_type')) {
            $query->where('supply_type', $supplyType);
        }

        if ($status = $table->filter('status')) {
            match ($status) {
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                'approved' => $query->where('is_approved', true),
                'unapproved' => $query->where('is_approved', false),
                default => null,
            };
        }

        return Inertia::render('vendors/index', [
            'vendors' => $table->paginate(
                $table->applySorting($query, self::SORTABLE, fallback: 'name')
            ),
            'table' => $table->toArray(),
            'can' => [
                'create' => $request->user()->can('create', Vendor::class),
                'export' => $request->user()->can('export', Vendor::class),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Vendor::class);

        return Inertia::render('vendors/create');
    }

    public function store(StoreVendorRequest $request): RedirectResponse
    {
        $this->authorize('create', Vendor::class);

        $vendor = Vendor::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Vendor {$vendor->code} created."]);

        return to_route('vendors.index');
    }

    public function show(Request $request, Vendor $vendor): Response
    {
        $this->authorize('view', $vendor);

        return Inertia::render('vendors/show', [
            'vendor' => $vendor,
            'can' => [
                'update' => $request->user()->can('update', $vendor),
                'delete' => $request->user()->can('delete', $vendor),
            ],
        ]);
    }

    public function edit(Vendor $vendor): Response
    {
        $this->authorize('update', $vendor);

        return Inertia::render('vendors/edit', ['vendor' => $vendor]);
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('update', $vendor);

        $vendor->update([
            ...$request->validated(),
            'updated_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Vendor {$vendor->code} updated."]);

        return to_route('vendors.show', $vendor);
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        $this->authorize('delete', $vendor);

        $vendor->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Vendor {$vendor->code} removed."]);

        return to_route('vendors.index');
    }
}
