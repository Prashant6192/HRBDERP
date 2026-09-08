<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Production', 'Quality Control', 'Warehouse', 'Procurement',
            'Accounts', 'Sales', 'Marketing', 'E-commerce', 'Design',
            'Human Resources', 'Administration',
        ]);

        return [
            'code' => strtoupper(substr(str_replace(' ', '', $name), 0, 6)),
            'name' => $name,
            'description' => null,
            'is_active' => true,
        ];
    }
}
