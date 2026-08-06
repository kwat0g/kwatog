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
            SodConflictRuleSeeder::class,  // REC-01 — SoD conflict matrix

            // Sprint 3 — Hire to Retire (Part 2: Payroll).
            GovernmentTableSeeder::class,      // Task 23 (2024 schedule)
            GovernmentTable2025Seeder::class,  // OGAMI-101 (2025 schedule)

            // Sprint 4 — Lean Accounting.
            // Order matters: full COA first, then the legacy payroll-codes upsert
            // is effectively a no-op (rows already exist), then payroll seeders.
            ChartOfAccountsSeeder::class,      // Task 31 (full ~45-account COA)
            PayrollChartAccountsSeeder::class, // Task 29 — idempotent upsert; preserved for back-compat.

            // Series R — Task R4. Catalog must run before role-default
            // layouts so widget keys exist when layouts reference them.
            DashboardWidgetSeeder::class,
            DashboardRoleLayoutSeeder::class,

            // KPI Scorecard — definition catalog for the KPI snapshot engine.
            KpiDefinitionSeeder::class,

        ]);

        // Operational master data is deliberately opt-in. A production or ordinary
        // fresh install receives only reference configuration and must obtain
        // employees, customers, inventory, transactions, and profiles from
        // imports or live user/API workflows.
        if (config('app.seed_reference_data')) {
            $this->call([
                DepartmentSeeder::class,
                PositionSeeder::class,
                ShiftSeeder::class,
                HolidaySeeder::class,
                LeaveTypeSeeder::class,
                UomSeeder::class,
                InventoryItemSeeder::class,
                WarehouseSeeder::class,
                CustomerSeeder::class,
                ProductSeeder::class,
                PriceAgreementSeeder::class,
                BomSeeder::class,
                MachineSeeder::class,
                MoldSeeder::class,
                MoldCompatibilitySeeder::class,
                DefectTypeSeeder::class,
                VehicleSeeder::class,
            ]);
        }

        // Demo records are deliberately opt-in.
        if (config('app.seed_demo_data')) {
            $this->call([
                DemoDataSeeder::class,
                DemoAccountSeeder::class,
                Sprint8DemoSeeder::class,
                SeriesEDemoSeeder::class,
                ComprehensiveDemoSeeder::class,
                RealisticDataSeeder::class,
                GoldenPathDemoSeeder::class,
            ]);
        }
    }
}
