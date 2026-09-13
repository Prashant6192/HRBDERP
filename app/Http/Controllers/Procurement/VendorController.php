<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Domain\Procurement\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StoreVendorRequest;
use App\Http\Requests\Procurement\UpdateVendorRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    /**
     * A vendor added without leaving the goods receipt screen: name and
     * GSTIN now, the rest later on the vendor's own page.
     */
    public function quick(Request $request): JsonResponse
    {
        $this->authorize('create', Vendor::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'size:15', Rule::unique('vendors', 'gstin')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'state' => ['nullable', 'string', 'max:128'],
            'supply_type' => ['nullable', Rule::in(['raw_material', 'packaging', 'services', 'mixed'])],
        ]);

        $code = $this->nextCode();

        $vendor = Vendor::create([
            ...$data,
            'code' => $code,
            'gstin' => isset($data['gstin']) ? strtoupper($data['gstin']) : null,
            'supply_type' => $data['supply_type'] ?? 'mixed',
            'country' => 'India',
            'is_approved' => true,
            'is_active' => true,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['value' => $vendor->id, 'label' => $vendor->name, 'code' => $vendor->code, 'gstin' => $vendor->gstin]);
    }

    private function nextCode(): string
    {
        $n = (int) Vendor::withTrashed()->count();

        do {
            $code = sprintf('VEN-%04d', ++$n);
        } while (Vendor::withTrashed()->where('code', $code)->exists());

        return $code;
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
