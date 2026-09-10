<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\HR\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['code' => 'EXEC',  'name' => 'Executive'],
            ['code' => 'HR',    'name' => 'Human Resources'],
            ['code' => 'FIN',   'name' => 'Finance & Accounting'],
            ['code' => 'PROD',  'name' => 'Production'],
            ['code' => 'QC',    'name' => 'Quality Control'],
            ['code' => 'WH',    'name' => 'Warehouse & Logistics'],
            ['code' => 'PUR',   'name' => 'Purchasing & Procurement'],
            ['code' => 'PPC',   'name' => 'Production Planning'],
            ['code' => 'MAINT', 'name' => 'Maintenance & Engineering'],
            ['code' => 'MOLD',  'name' => 'Mold Department'],
            ['code' => 'IMPEX', 'name' => 'Import/Export'],
            ['code' => 'ADMIN', 'name' => 'Admin & General Affairs'],
            // Owns the IT clearance items (equipment returned, accounts
            // disabled) so the per-department signing gate can resolve them.
            ['code' => 'IT',    'name' => 'Information Technology'],
        ];

        foreach ($departments as $d) {
            Department::updateOrCreate(
                ['code' => $d['code']],
                ['name' => $d['name'], 'is_active' => true],
            );
        }

        $this->command?->info('Departments seeded.');
    }
}
