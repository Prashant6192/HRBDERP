<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Warehousing\Enums\AssignmentStatus;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAssignment>
 */
class EmployeeAssignmentFactory extends Factory
{
    protected $model = EmployeeAssignment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'facility_id' => Facility::factory(),
            'store_id' => null,
            'is_primary' => true,
            'effective_from' => now()->toDateString(),
            'status' => AssignmentStatus::Active,
        ];
    }
}
