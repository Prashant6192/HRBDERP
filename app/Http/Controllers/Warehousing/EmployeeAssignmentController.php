<?php

declare(strict_types=1);

namespace App\Http\Controllers\Warehousing;

use App\Domain\Warehousing\Exceptions\FacilityException;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\EmployeeAssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehousing\StoreEmployeeAssignmentRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Who works where.
 */
class EmployeeAssignmentController extends Controller
{
    public function __construct(private readonly EmployeeAssignmentService $assignments) {}

    public function store(StoreEmployeeAssignmentRequest $request, Facility $facility): RedirectResponse
    {
        $data = $request->validated();
        $store = ! empty($data['store_id']) ? Warehouse::query()->findOrFail($data['store_id']) : null;

        Gate::authorize($store === null ? 'user.assign_facility' : 'user.assign_store');

        $employee = User::query()->findOrFail($data['user_id']);

        try {
            $this->assignments->assign($employee, $facility, $store, $data, $request->user()->id);
        } catch (FacilityException $e) {
            return back()->withErrors(['user_id' => $e->getMessage()]);
        }

        return back()->withToast('success', "{$employee->name} assigned to ".($store?->name ?? $facility->name).'.');
    }

    public function destroy(Request $request, EmployeeAssignment $assignment): RedirectResponse
    {
        Gate::authorize($assignment->store_id === null ? 'user.assign_facility' : 'user.assign_store');

        $assignment->load('user');
        $this->assignments->end($assignment, $request->user()->id);

        return back()->withToast('success', "{$assignment->user->name}'s assignment ended.");
    }

    public function primary(Request $request, EmployeeAssignment $assignment): RedirectResponse
    {
        Gate::authorize('user.assign_facility');

        $this->assignments->makePrimary($assignment);

        return back()->withToast('success', 'Primary assignment updated.');
    }
}
