<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Permission catalog: { module => [ {slug, name, description}, ... ] }.
     *
     * @return array<string, array<int, array{slug: string, name: string, description?: string}>>
     */
    private function permissionCatalog(): array
    {
        return [
            // Admin / system
            'admin' => [
                ['slug' => 'admin.roles.manage',       'name' => 'Manage Roles & Permissions'],
                ['slug' => 'admin.settings.manage',    'name' => 'Manage System Settings'],
                ['slug' => 'admin.audit_logs.view',    'name' => 'View Audit Logs'],
                ['slug' => 'admin.users.manage',       'name' => 'Manage Users'],
                ['slug' => 'admin.gov_tables.manage',  'name' => 'Manage Government Contribution Tables'],
            ],

            // HR
            'hr' => [
                ['slug' => 'hr.departments.view',         'name' => 'View Departments'],
                ['slug' => 'hr.departments.manage',       'name' => 'Manage Departments'],
                ['slug' => 'hr.positions.view',           'name' => 'View Positions'],
                ['slug' => 'hr.positions.manage',         'name' => 'Manage Positions'],
                ['slug' => 'hr.employees.view',           'name' => 'View Employees'],
                ['slug' => 'hr.employees.create',         'name' => 'Create Employees'],
                ['slug' => 'hr.employees.edit',           'name' => 'Edit Employees'],
                ['slug' => 'hr.employees.delete',         'name' => 'Delete Employees'],
                ['slug' => 'hr.employees.export',         'name' => 'Export Employees'],
                ['slug' => 'hr.employees.view_sensitive', 'name' => 'View Sensitive Employee Data (SSS, TIN, Bank)'],
                ['slug' => 'hr.employees.separate',       'name' => 'Initiate Employee Separation'],
            ],

            // Attendance
            'attendance' => [
                ['slug' => 'attendance.view',          'name' => 'View Attendance'],
                ['slug' => 'attendance.import',        'name' => 'Import Attendance (CSV)'],
                ['slug' => 'attendance.edit',          'name' => 'Edit Attendance'],
                ['slug' => 'attendance.shifts.manage', 'name' => 'Manage Shifts'],
                ['slug' => 'attendance.holidays.manage', 'name' => 'Manage Holidays'],
                ['slug' => 'attendance.ot.approve',    'name' => 'Approve Overtime'],
            ],

            // Leave
            'leave' => [
                ['slug' => 'leave.view',           'name' => 'View Leave Requests'],
                ['slug' => 'leave.create',         'name' => 'Create Leave Request'],
                ['slug' => 'leave.approve_dept',   'name' => 'Approve Leave (Dept Head)'],
                ['slug' => 'leave.approve_hr',     'name' => 'Approve Leave (HR)'],
                ['slug' => 'leave.types.manage',   'name' => 'Manage Leave Types'],
            ],

            // Payroll
            'payroll' => [
                ['slug' => 'payroll.view',                'name' => 'View Payroll'],
                ['slug' => 'payroll.periods.create',      'name' => 'Create Payroll Period'],
                ['slug' => 'payroll.periods.compute',     'name' => 'Compute Payroll'],
                ['slug' => 'payroll.periods.approve',     'name' => 'Approve Payroll'],
                ['slug' => 'payroll.periods.finalize',    'name' => 'Finalize Payroll'],
                ['slug' => 'payroll.adjustments.create',  'name' => 'Create Payroll Adjustment'],
                ['slug' => 'payroll.payslip.view_all',    'name' => 'View Any Payslip'],
                ['slug' => 'payroll.thirteenth_month.run', 'name' => 'Run 13th Month Pay'],
            ],

            // Loans
            'loans' => [
                ['slug' => 'loans.view',          'name' => 'View Loans'],
                ['slug' => 'loans.create',        'name' => 'Create Loan / Cash Advance'],
                ['slug' => 'loans.approve',       'name' => 'Approve Loan'],
                ['slug' => 'loans.write_off',     'name' => 'Write Off Loan'],
            ],

            // Accounting
            'accounting' => [
                ['slug' => 'accounting.view',                 'name' => 'View Accounting'],
                ['slug' => 'accounting.coa.manage',           'name' => 'Manage Chart of Accounts'],
                ['slug' => 'accounting.journal.create',       'name' => 'Create Journal Entries'],
                ['slug' => 'accounting.journal.post',         'name' => 'Post Journal Entries'],
                ['slug' => 'accounting.bills.create',         'name' => 'Create Bills'],
                ['slug' => 'accounting.bills.pay',            'name' => 'Pay Bills'],
                ['slug' => 'accounting.invoices.create',      'name' => 'Create Invoices'],
                ['slug' => 'accounting.invoices.collect',     'name' => 'Record Collections'],
                ['slug' => 'accounting.statements.view',      'name' => 'View Financial Statements'],
            ],

            // Inventory
            'inventory' => [
                ['slug' => 'inventory.view',         'name' => 'View Inventory'],
                ['slug' => 'inventory.items.manage', 'name' => 'Manage Items'],
                ['slug' => 'inventory.grn.create',   'name' => 'Create GRN'],
                ['slug' => 'inventory.issue.create', 'name' => 'Issue Materials'],
                ['slug' => 'inventory.adjust',       'name' => 'Adjust Stock'],
            ],

            // Purchasing
            'purchasing' => [
                ['slug' => 'purchasing.view',         'name' => 'View Purchasing'],
                ['slug' => 'purchasing.pr.create',    'name' => 'Create Purchase Request'],
                ['slug' => 'purchasing.pr.approve',   'name' => 'Approve Purchase Request'],
                ['slug' => 'purchasing.po.create',    'name' => 'Create Purchase Order'],
                ['slug' => 'purchasing.po.approve',   'name' => 'Approve Purchase Order'],
                ['slug' => 'purchasing.po.send',      'name' => 'Send PO to Supplier'],
            ],

            // Supply Chain
            'supply_chain' => [
                ['slug' => 'supply_chain.view',                 'name' => 'View Supply Chain'],
                ['slug' => 'supply_chain.shipments.manage',     'name' => 'Manage Shipments'],
                ['slug' => 'supply_chain.deliveries.create',    'name' => 'Create Deliveries'],
                ['slug' => 'supply_chain.deliveries.confirm',   'name' => 'Confirm Customer Delivery'],
            ],

            // Production
            'production' => [
                ['slug' => 'production.view',           'name' => 'View Production'],
                ['slug' => 'production.wo.create',      'name' => 'Create Work Order'],
                ['slug' => 'production.wo.confirm',     'name' => 'Confirm Work Order'],
                ['slug' => 'production.wo.record',      'name' => 'Record Production Output'],
                ['slug' => 'production.machines.manage','name' => 'Manage Machines'],
                ['slug' => 'production.molds.manage',   'name' => 'Manage Molds'],
            ],

            // MRP
            'mrp' => [
                ['slug' => 'mrp.view',           'name' => 'View MRP'],
                ['slug' => 'mrp.run',            'name' => 'Run MRP'],
                ['slug' => 'mrp.schedule',       'name' => 'Schedule Production'],
                ['slug' => 'mrp.boms.manage',    'name' => 'Manage Bills of Materials'],
            ],

            // CRM
            'crm' => [
                ['slug' => 'crm.view',                 'name' => 'View CRM'],
                ['slug' => 'crm.customers.manage',     'name' => 'Manage Customers'],
                ['slug' => 'crm.so.create',            'name' => 'Create Sales Orders'],
                ['slug' => 'crm.complaints.manage',    'name' => 'Manage Complaints'],
            ],

            // Quality
            'quality' => [
                ['slug' => 'quality.view',                 'name' => 'View Quality'],
                ['slug' => 'quality.inspections.create',   'name' => 'Create Inspections'],
                ['slug' => 'quality.inspections.edit',     'name' => 'Edit Inspections'],
                ['slug' => 'quality.specs.manage',         'name' => 'Manage Inspection Specs'],
                ['slug' => 'quality.ncr.manage',           'name' => 'Manage NCRs'],
            ],

            // Maintenance
            'maintenance' => [
                ['slug' => 'maintenance.view',           'name' => 'View Maintenance'],
                ['slug' => 'maintenance.wo.create',      'name' => 'Create Maintenance Work Order'],
                ['slug' => 'maintenance.wo.assign',      'name' => 'Assign Maintenance Work Order'],
                ['slug' => 'maintenance.wo.complete',    'name' => 'Complete Maintenance Work Order'],
                ['slug' => 'maintenance.schedules.manage','name' => 'Manage Maintenance Schedules'],
            ],
        ];
    }

    /**
     * Role definitions: { slug => { name, description, permissions: array | '*' } }.
     *
     * @return array<string, array{name: string, description: string, permissions: array<int, string>|string}>
     */
    private function roleCatalog(): array
    {
        return [
            'system_admin' => [
                'name' => 'System Administrator',
                'description' => 'Full access to every module. Override gate via AuthServiceProvider.',
                'permissions' => '*',
            ],
            'hr_officer' => [
                'name' => 'HR Officer',
                'description' => 'Manages employees, attendance, leave; sees sensitive HR data.',
                'permissions' => array_merge(
                    $this->module('hr'),
                    $this->module('attendance'),
                    $this->module('leave'),
                    [
                        'payroll.view',
                        'payroll.payslip.view_all',
                        'payroll.periods.create',
                        'payroll.periods.compute',
                        'payroll.periods.approve',
                        'payroll.adjustments.create',
                        'payroll.thirteenth_month.run',
                    ],
                ),
            ],
            'finance_officer' => [
                'name' => 'Finance Officer',
                'description' => 'Manages payroll finalization, accounting, vendor & customer ledgers.',
                'permissions' => array_merge(
                    $this->module('payroll'),
                    $this->module('accounting'),
                    $this->module('loans'),
                    ['admin.gov_tables.manage'],
                ),
            ],
            'production_manager' => [
                'name' => 'Production Manager',
                'description' => 'Oversees work orders, output, OEE.',
                'permissions' => array_merge(
                    $this->module('production'),
                    ['mrp.view', 'mrp.schedule', 'inventory.view', 'quality.view'],
                ),
            ],
            'ppc_head' => [
                'name' => 'PPC Head',
                'description' => 'Production Planning & Control — owns the schedule and BOMs.',
                'permissions' => array_merge(
                    $this->module('mrp'),
                    ['production.view', 'production.wo.create', 'production.wo.confirm'],
                ),
            ],
            'purchasing_officer' => [
                'name' => 'Purchasing Officer',
                'description' => 'Manages PRs, POs, vendor relationships.',
                'permissions' => array_merge(
                    $this->module('purchasing'),
                    ['inventory.view', 'inventory.grn.create', 'supply_chain.shipments.manage'],
                ),
            ],
            'warehouse_staff' => [
                'name' => 'Warehouse Staff',
                'description' => 'Receives goods, issues materials, counts stock.',
                'permissions' => [
                    'inventory.view', 'inventory.items.manage',
                    'inventory.grn.create', 'inventory.issue.create', 'inventory.adjust',
                ],
            ],
            'qc_inspector' => [
                'name' => 'QC Inspector',
                'description' => 'Logs inspection results, raises NCRs.',
                'permissions' => $this->module('quality'),
            ],
            'maintenance_tech' => [
                'name' => 'Maintenance Technician',
                'description' => 'Executes maintenance work orders.',
                'permissions' => ['maintenance.view', 'maintenance.wo.complete'],
            ],
            'impex_officer' => [
                'name' => 'ImpEx Officer',
                'description' => 'Tracks imported shipments and customs documents.',
                'permissions' => ['supply_chain.view', 'supply_chain.shipments.manage', 'purchasing.view'],
            ],
            'department_head' => [
                'name' => 'Department Head',
                'description' => 'Approves leaves, OT, PRs for their department.',
                'permissions' => [
                    'hr.employees.view',
                    'attendance.view', 'attendance.ot.approve',
                    'leave.view', 'leave.approve_dept',
                    'purchasing.view', 'purchasing.pr.approve',
                ],
            ],
            'employee' => [
                'name' => 'Employee',
                'description' => 'Self-service portal access only.',
                'permissions' => [
                    'leave.view', 'leave.create',
                    // Self-service: payroll.view is server-scoped to own payrolls
                    // by PayrollController::index() — employees never see others.
                    'payroll.view',
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function module(string $module): array
    {
        return array_map(
            fn (array $p) => $p['slug'],
            $this->permissionCatalog()[$module] ?? [],
        );
    }

    public function run(): void
    {
        // 1. Insert/update permissions.
        $allPermissions = [];
        foreach ($this->permissionCatalog() as $module => $perms) {
            foreach ($perms as $p) {
                $allPermissions[] = Permission::updateOrCreate(
                    ['slug' => $p['slug']],
                    [
                        'name'        => $p['name'],
                        'module'      => $module,
                        'description' => $p['description'] ?? null,
                    ],
                );
            }
        }
        $allSlugs = collect($allPermissions)->pluck('id', 'slug');

        // 2. Insert/update roles + sync permissions.
        foreach ($this->roleCatalog() as $slug => $def) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'        => $def['name'],
                    'description' => $def['description'],
                ],
            );

            $ids = $def['permissions'] === '*'
                ? $allSlugs->values()->all()
                : array_values(array_filter(array_map(
                    fn (string $s) => $allSlugs[$s] ?? null,
                    (array) $def['permissions'],
                )));

            $role->permissions()->sync($ids);
        }

        $this->command?->info('Roles + permissions seeded.');
    }
}
