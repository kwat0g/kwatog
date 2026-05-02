<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Sprint 1 foundation.
            RolePermissionSeeder::class,   // Task 10
            AdminUserSeeder::class,        // Task 9
            WorkflowSeeder::class,         // Task 11
            SettingsSeeder::class,         // Task 12

            // Sprint 2 — Hire to Retire (Part 1).
            DepartmentSeeder::class,       // Task 13
            PositionSeeder::class,         // Task 13
            ShiftSeeder::class,            // Task 16
            HolidaySeeder::class,          // Task 17
            LeaveTypeSeeder::class,        // Task 20

            // Sprint 3 — Hire to Retire (Part 2: Payroll).
            GovernmentTableSeeder::class,      // Task 23
            PayrollChartAccountsSeeder::class, // Task 29 — front-loads minimum COA so GL posting works before Sprint 4 ships.
        ]);
    }
}
