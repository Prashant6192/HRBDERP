<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Models\FacilityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\FacilityTypeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The facility type master and the capabilities each type starts with.
 */
class FacilityTypeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('setting.view');

        return Inertia::render('settings/facility-types', [
            'types' => FacilityType::query()->withCount('facilities')->ordered()->get()
                ->map(fn (FacilityType $t) => [
                    'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'description' => $t->description,
                    'default_capabilities' => $t->default_capabilities ?? [], 'is_system' => $t->is_system, 'is_active' => $t->is_active,
                    'sort_order' => $t->sort_order, 'facilities_count' => $t->facilities_count,
                ])->all(),
            'capabilities' => array_map(fn (FacilityCapability $c) => ['key' => $c->value, 'label' => $c->label(), 'badge' => $c->badge(), 'description' => $c->description()], FacilityCapability::cases()),
            'can' => ['edit' => $request->user()->can('setting.edit')],
        ]);
    }

    public function store(FacilityTypeRequest $request): RedirectResponse
    {
        Gate::authorize('setting.edit');

        $type = FacilityType::create([...$request->validated(), 'is_system' => false]);

        return back()->withToast('success', "Facility type {$type->name} added.");
    }

    public function update(FacilityTypeRequest $request, FacilityType $facilityType): RedirectResponse
    {
        Gate::authorize('setting.edit');

        $data = $request->validated();

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $facilityType->facilities()->where('is_active', true)->exists()) {
            return back()->withErrors(['is_active' => "{$facilityType->name} is used by active facilities and cannot be deactivated."]);
        }

        $facilityType->update($data);

        return back()->withToast('success', "Facility type {$facilityType->name} updated.");
    }
}
