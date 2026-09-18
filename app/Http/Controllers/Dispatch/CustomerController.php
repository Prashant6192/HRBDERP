<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dispatch;

use App\Domain\Contract\Models\Client;
use App\Domain\Dispatch\Enums\CustomerKind;
use App\Domain\Dispatch\Models\Customer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dispatch\StoreCustomerRequest;
use App\Http\Requests\Dispatch\UpdateCustomerRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who finished goods go to: contract clients taking their own goods,
 * marketplaces, distributors.
 */
class CustomerController extends Controller
{
    private const array SORTABLE = ['code', 'name', 'kind', 'billing_city', 'is_active', 'created_at'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['kind', 'status']);

        $query = Customer::query()
            ->with('client:id,code,name')
            ->withCount(['dispatches as dispatches_count' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->search($table->search);

        if ($kind = $table->filter('kind')) {
            $query->where('kind', $kind);
        }

        if ($status = $table->filter('status')) {
            $query->where('is_active', $status === 'active');
        }

        $rows = $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'name'))
            ->through(fn (Customer $c): array => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'legal_name' => $c->legal_name,
                'gstin' => $c->gstin,
                'kind' => $c->kind->value,
                'kind_label' => $c->kind->label(),
                'client' => $c->client?->name,
                'billing_city' => $c->billing_city,
                'billing_state' => $c->billing_state,
                'contact_person' => $c->contact_person,
                'phone' => $c->phone,
                'dispatches_count' => $c->dispatches_count,
                'is_active' => $c->is_active,
            ]);

        return Inertia::render('customers/index', [
            'customers' => $rows,
            'table' => $table->toArray(),
            'kinds' => CustomerKind::options(),
            'can' => [
                'create' => $request->user()->can('create', Customer::class),
                'edit' => $request->user()->can('dispatch.edit'),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Customer::class);

        return Inertia::render('customers/create', [
            'nextCode' => Customer::nextCode(),
            'kinds' => CustomerKind::options(),
            'clients' => $this->clientOptions(),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $customer = Customer::create([
            ...$request->validated(),
            'code' => Customer::nextCode(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('customers.index')->withToast('success', "Customer {$customer->code} {$customer->name} added.");
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('customers/edit', [
            'customer' => $customer,
            'kinds' => CustomerKind::options(),
            'clients' => $this->clientOptions(),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $customer->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        return redirect()->route('customers.index')->withToast('success', "Customer {$customer->code} updated.");
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function clientOptions(): array
    {
        return Client::query()->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Client $c): array => ['value' => $c->id, 'label' => "{$c->name} ({$c->code})"])
            ->all();
    }
}
