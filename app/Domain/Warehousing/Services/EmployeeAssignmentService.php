<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Warehousing\Enums\AssignmentStatus;
use App\Domain\Warehousing\Exceptions\FacilityException;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Puts people at facilities and stores. One user record, any number of
 * assignments; exactly one of them primary.
 */
class EmployeeAssignmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FacilityAccess $access,
    ) {}

    /**
     * @param  array{is_primary?: bool, designation?: string|null, effective_from?: string|null, effective_to?: string|null, notes?: string|null}  $options
     */
    public function assign(User $employee, Facility $facility, ?Warehouse $store, array $options, ?int $assignedBy): EmployeeAssignment
    {
        if ($store !== null && $store->facility_id !== $facility->id) {
            throw new FacilityException("{$store->name} is not a store at {$facility->name}.");
        }

        if ($store !== null && $store->is_system) {
            throw new FacilityException('People cannot be assigned to a system store.');
        }

        return DB::transaction(function () use ($employee, $facility, $store, $options, $assignedBy): EmployeeAssignment {
            $isPrimary = (bool) ($options['is_primary'] ?? false);

            // The first assignment a person gets is their primary one.
            if (! $employee->activeAssignments()->exists()) {
                $isPrimary = true;
            }

            if ($isPrimary) {
                $employee->activeAssignments()->where('is_primary', true)->update(['is_primary' => false]);
            }

            $assignment = EmployeeAssignment::query()
                ->where('user_id', $employee->id)
                ->where('facility_id', $facility->id)
                ->where('store_id', $store?->id)
                ->active()
                ->first();

            $attributes = [
                'is_primary' => $isPrimary,
                'designation' => $options['designation'] ?? $employee->designation,
                'effective_from' => $options['effective_from'] ?? now()->toDateString(),
                'effective_to' => $options['effective_to'] ?? null,
                'notes' => $options['notes'] ?? null,
                'assigned_by' => $assignedBy,
                'status' => AssignmentStatus::Active,
            ];

            if ($assignment !== null) {
                $assignment->fill($attributes)->save();
            } else {
                $assignment = EmployeeAssignment::create([
                    'user_id' => $employee->id,
                    'facility_id' => $facility->id,
                    'store_id' => $store?->id,
                    ...$attributes,
                ]);
            }

            $this->audit->log(
                AuditAction::Updated,
                $employee,
                description: sprintf('%s assigned to %s%s', $employee->name, $facility->name, $store ? " / {$store->name}" : ''),
                context: ['facility_id' => $facility->id, 'store_id' => $store?->id, 'assigned_by' => $assignedBy],
            );

            $this->access->forget($employee);

            return $assignment->refresh();
        });
    }

    public function end(EmployeeAssignment $assignment, ?int $endedBy): EmployeeAssignment
    {
        return DB::transaction(function () use ($assignment, $endedBy): EmployeeAssignment {
            $assignment = EmployeeAssignment::query()->lockForUpdate()->with(['user', 'facility', 'store'])->findOrFail($assignment->id);

            if ($assignment->status !== AssignmentStatus::Active) {
                return $assignment;
            }

            $assignment->fill([
                'status' => AssignmentStatus::Ended,
                'effective_to' => now()->toDateString(),
                'is_primary' => false,
            ])->save();

            // Someone has to be primary while any assignment is left.
            $next = $assignment->user->activeAssignments()->orderByDesc('is_primary')->orderBy('id')->first();

            if ($next !== null && ! $next->is_primary) {
                $next->fill(['is_primary' => true])->save();
            }

            $this->audit->log(
                AuditAction::Updated,
                $assignment->user,
                description: sprintf('%s no longer assigned to %s%s', $assignment->user->name, $assignment->facility->name, $assignment->store ? " / {$assignment->store->name}" : ''),
                context: ['facility_id' => $assignment->facility_id, 'store_id' => $assignment->store_id, 'ended_by' => $endedBy],
            );

            $this->access->forget($assignment->user);

            return $assignment;
        });
    }

    public function makePrimary(EmployeeAssignment $assignment): EmployeeAssignment
    {
        return DB::transaction(function () use ($assignment): EmployeeAssignment {
            EmployeeAssignment::query()->where('user_id', $assignment->user_id)->where('is_primary', true)->update(['is_primary' => false]);
            $assignment->fill(['is_primary' => true])->save();

            return $assignment;
        });
    }
}
