<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const DEPARTMENTS = [
        'MGMT' => 'Management',
        'PROD' => 'Production',
        'QC' => 'Quality Control',
        'WH' => 'Warehouse',
        'PUR' => 'Procurement',
        'ACCT' => 'Accounts',
        'SALES' => 'Sales',
        'MKTG' => 'Marketing',
        'ECOM' => 'E-commerce',
        'DSGN' => 'Design',
        'ADMIN' => 'Administration',
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $code => $name) {
            Department::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_active' => true],
            );
        }
    }
}
