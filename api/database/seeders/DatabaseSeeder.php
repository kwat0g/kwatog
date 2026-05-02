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

            // Sprint 4 — Lean Accounting.
            // Order matters: full COA first, then the legacy payroll-codes upsert
            // is effectively a no-op (rows already exist), then payroll seeders.
            ChartOfAccountsSeeder::class,      // Task 31 (full ~45-account COA)
            PayrollChartAccountsSeeder::class, // Task 29 — idempotent upsert; preserved for back-compat.

            // Sprint 5 — Procure to Pay (Part 1).
            InventoryItemSeeder::class,        // Task 39
            WarehouseSeeder::class,            // Task 40

            // Sprint 6 — Order to Cash (Part 1: CRM + MRP + Production).
            CustomerSeeder::class,             // Task 47 (upstream of CRM)
            ProductSeeder::class,              // Task 47
            PriceAgreementSeeder::class,       // Task 47
            BomSeeder::class,                  // Task 49 (depends on products + items)
        ]);
    }
}
