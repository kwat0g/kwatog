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
                // Series R — Task R2: per-user permission overrides.
                ['slug' => 'admin.users.manage_permissions', 'name' => 'Manage Per-User Permission Overrides'],
                ['slug' => 'admin.gov_tables.manage',  'name' => 'Manage Government Contribution Tables'],
                ['slug' => 'admin.print.bulk',         'name' => 'Bulk Print Approved Forms'],
                // Series E (E2/E3) — exports + document vault.
                ['slug' => 'admin.scheduled_exports.view', 'name' => 'View Scheduled Exports'],
                // Series F — Task F7: company-wide activity feed.
                ['slug' => 'admin.activity.view',          'name' => 'View System Activity Feed'],
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
                // Series E (E1/E3) — view generated documents on an employee detail page.
                ['slug' => 'hr.employees.documents.view', 'name' => 'View Employee Documents'],
                ['slug' => 'hr.employees.view_sensitive', 'name' => 'View Sensitive Employee Data (SSS, TIN, Bank)'],
                ['slug' => 'hr.employees.separate',       'name' => 'Initiate Employee Separation'],
                // U1 — system account lifecycle.
                ['slug' => 'hr.employees.account_status',     'name' => 'View Employee System-Account Status'],
                ['slug' => 'hr.employees.provision_account',  'name' => 'Provision Employee System Account'],
                ['slug' => 'hr.employees.deactivate_account', 'name' => 'Deactivate Employee System Account'],
                ['slug' => 'hr.employees.reset_password',     'name' => 'Reset Employee System Account Password'],
                // Series F — Task F5: directory + org chart (lighter trust than full employee view).
                ['slug' => 'hr.directory.view',               'name' => 'View Employee Directory'],
                // Task SS2 — Finance leg of employee bank-account change approval.
                ['slug' => 'hr.profile_updates.finance_review', 'name' => 'Approve Employee Bank-Account Changes (Finance)'],
            ],

            // Attendance
            'attendance' => [
                ['slug' => 'attendance.view',          'name' => 'View Attendance'],
                ['slug' => 'attendance.import',        'name' => 'Import Attendance (CSV)'],
                ['slug' => 'attendance.edit',          'name' => 'Edit Attendance'],
                ['slug' => 'attendance.shifts.manage', 'name' => 'Manage Shifts'],
                ['slug' => 'attendance.holidays.manage', 'name' => 'Manage Holidays'],
                ['slug' => 'attendance.ot.create',     'name' => 'Create Overtime Request'],
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
                // H-8 — admin escape hatch for periods stuck at Processing because
                // the payroll job worker crashed before its finally block ran.
                ['slug' => 'payroll.periods.force_unlock', 'name' => 'Force-unlock Payroll Period'],
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

            // Accounting (Sprint 4 — Lean Accounting)
            'accounting' => [
                ['slug' => 'accounting.view',                 'name' => 'View Accounting'],
                ['slug' => 'accounting.dashboard.view',       'name' => 'View Finance Dashboard'],
                // Chart of Accounts
                ['slug' => 'accounting.coa.view',             'name' => 'View Chart of Accounts'],
                ['slug' => 'accounting.coa.manage',           'name' => 'Manage Chart of Accounts'],
                ['slug' => 'accounting.coa.deactivate',       'name' => 'Deactivate Accounts'],
                // Journal Entries
                ['slug' => 'accounting.journal.view',         'name' => 'View Journal Entries'],
                ['slug' => 'accounting.journal.create',       'name' => 'Create Journal Entries'],
                ['slug' => 'accounting.journal.post',         'name' => 'Post Journal Entries'],
                ['slug' => 'accounting.journal.reverse',      'name' => 'Reverse Posted Journal Entries'],
                // Vendors & Bills (AP)
                ['slug' => 'accounting.vendors.view',         'name' => 'View Vendors'],
                ['slug' => 'accounting.vendors.manage',       'name' => 'Manage Vendors'],
                ['slug' => 'accounting.bills.view',           'name' => 'View Bills'],
                ['slug' => 'accounting.bills.create',         'name' => 'Create Bills'],
                ['slug' => 'accounting.bills.update',         'name' => 'Update / Cancel Bills'],
                ['slug' => 'accounting.bills.pay',            'name' => 'Pay Bills'],
                // Customers & Invoices (AR)
                ['slug' => 'accounting.customers.view',       'name' => 'View Customers'],
                ['slug' => 'accounting.customers.manage',     'name' => 'Manage Customers'],
                ['slug' => 'accounting.invoices.view',        'name' => 'View Invoices'],
                ['slug' => 'accounting.invoices.create',      'name' => 'Create Invoices'],
                ['slug' => 'accounting.invoices.update',      'name' => 'Update / Cancel Invoices'],
                ['slug' => 'accounting.invoices.collect',     'name' => 'Record Collections'],
                // Statements
                ['slug' => 'accounting.statements.view',      'name' => 'View Financial Statements'],
                ['slug' => 'accounting.statements.export',    'name' => 'Export Statements (CSV/PDF)'],
            ],

            // Inventory
            'inventory' => [
                ['slug' => 'inventory.view',                'name' => 'View Inventory'],
                ['slug' => 'inventory.items.manage',        'name' => 'Manage Items'],
                ['slug' => 'inventory.warehouse.manage',    'name' => 'Manage Warehouse Structure'],
                ['slug' => 'inventory.grn.create',          'name' => 'Create / Accept GRN'],
                ['slug' => 'inventory.issue.create',        'name' => 'Issue Materials'],
                ['slug' => 'inventory.adjust',              'name' => 'Adjust / Transfer Stock'],
                // ADV8 — WMS
                ['slug' => 'inventory.stock_count.view',    'name' => 'View Stock Count Sessions'],
                ['slug' => 'inventory.stock_count.manage',  'name' => 'Create / Complete Stock Count Sessions'],
                ['slug' => 'inventory.picking.view',        'name' => 'View Picking Lists'],
            ],

            // Purchasing
            'purchasing' => [
                ['slug' => 'purchasing.view',         'name' => 'View Purchasing'],
                ['slug' => 'purchasing.pr.create',    'name' => 'Create Purchase Request'],
                ['slug' => 'purchasing.pr.approve',   'name' => 'Approve Purchase Request'],
                ['slug' => 'purchasing.po.create',    'name' => 'Create Purchase Order'],
                ['slug' => 'purchasing.po.approve',   'name' => 'Approve Purchase Order'],
                ['slug' => 'purchasing.po.send',      'name' => 'Send PO to Supplier'],
                // Series F — Task F4: supplier performance dashboard.
                ['slug' => 'purchasing.suppliers.performance.view',     'name' => 'View Supplier Performance'],
                ['slug' => 'purchasing.suppliers.performance.recompute','name' => 'Recompute Supplier Performance Snapshots'],
            ],

            // Supply Chain
            'supply_chain' => [
                ['slug' => 'supply_chain.view',                 'name' => 'View Supply Chain'],
                ['slug' => 'supply_chain.shipments.manage',     'name' => 'Manage Shipments'],
                ['slug' => 'supply_chain.fleet.manage',         'name' => 'Manage Vehicles'],
                ['slug' => 'supply_chain.deliveries.create',    'name' => 'Create Deliveries'],
                ['slug' => 'supply_chain.deliveries.confirm',   'name' => 'Confirm Customer Delivery'],
            ],

            // Production — Sprint 6 Tasks 51, 55–58
            'production' => [
                ['slug' => 'production.view',                  'name' => 'View Production'],
                ['slug' => 'production.wo.create',             'name' => 'Create Work Order'],
                ['slug' => 'production.wo.confirm',            'name' => 'Confirm Work Order'],
                ['slug' => 'production.wo.record',             'name' => 'Record Production Output'],
                ['slug' => 'production.work_orders.view',      'name' => 'View Work Orders'],
                ['slug' => 'production.work_orders.lifecycle', 'name' => 'Transition Work Order Status'],
                ['slug' => 'production.machines.manage',       'name' => 'Manage Machines'],
                ['slug' => 'production.machines.transition',   'name' => 'Transition Machine Status'],
                ['slug' => 'production.molds.manage',          'name' => 'Manage Molds'],
                ['slug' => 'production.schedule.view',         'name' => 'View Production Schedule'],
                ['slug' => 'production.schedule.confirm',      'name' => 'Confirm Production Schedule'],
                ['slug' => 'production.dashboard.view',        'name' => 'View Production Dashboard'],
            ],

            // MRP — Sprint 6 Tasks 49, 50, 52, 53
            'mrp' => [
                ['slug' => 'mrp.view',           'name' => 'View MRP'],
                // Sprint 6 audit: 'mrp.run' was a stale duplicate of
                // mrp.plans.run (which is what the routes actually use).
                // Removed from the catalogue; mrp.plans.run remains.
                ['slug' => 'mrp.schedule',       'name' => 'Schedule Production'],
                ['slug' => 'mrp.boms.view',      'name' => 'View Bills of Materials'],
                ['slug' => 'mrp.boms.manage',    'name' => 'Manage Bills of Materials'],
                ['slug' => 'mrp.machines.view',  'name' => 'View Machines'],
                ['slug' => 'mrp.molds.view',     'name' => 'View Molds'],
                ['slug' => 'mrp.plans.view',     'name' => 'View MRP Plans'],
                ['slug' => 'mrp.plans.run',      'name' => 'Re-run MRP Plan'],
                // Task A1
                ['slug' => 'mrp.runs.view',      'name' => 'View MRP Run History'],
                ['slug' => 'mrp.runs.trigger',   'name' => 'Trigger MRP Run Manually'],
            ],

            // CRM — Sprint 6 Tasks 47, 48
            'crm' => [
                ['slug' => 'crm.view',                       'name' => 'View CRM'],
                ['slug' => 'crm.customers.manage',           'name' => 'Manage Customers'],
                ['slug' => 'crm.products.view',              'name' => 'View Products'],
                ['slug' => 'crm.products.manage',            'name' => 'Manage Products'],
                ['slug' => 'crm.price_agreements.view',      'name' => 'View Price Agreements'],
                ['slug' => 'crm.price_agreements.manage',    'name' => 'Manage Price Agreements'],
                ['slug' => 'crm.sales_orders.view',          'name' => 'View Sales Orders'],
                ['slug' => 'crm.sales_orders.create',        'name' => 'Create Sales Orders'],
                ['slug' => 'crm.sales_orders.update',        'name' => 'Update Draft Sales Orders'],
                ['slug' => 'crm.sales_orders.delete',        'name' => 'Delete Draft Sales Orders'],
                ['slug' => 'crm.sales_orders.confirm',       'name' => 'Confirm Sales Orders'],
                ['slug' => 'crm.sales_orders.cancel',        'name' => 'Cancel Sales Orders'],
                ['slug' => 'crm.so.create',                  'name' => 'Create Sales Orders (legacy)'],
                ['slug' => 'crm.complaints.manage',          'name' => 'Manage Complaints'],
            ],

            // Quality
            'quality' => [
                ['slug' => 'quality.view',                 'name' => 'View Quality'],
                ['slug' => 'quality.inspections.create',   'name' => 'Create Inspections'],
                ['slug' => 'quality.inspections.edit',     'name' => 'Edit Inspections'],
                // Sprint 7 Task 60: read + lifecycle slugs that match the
                // route middleware (view / manage). Existing .create / .edit
                // are kept for backward compat with seeded roles.
                ['slug' => 'quality.inspections.view',     'name' => 'View Inspections'],
                ['slug' => 'quality.inspections.manage',   'name' => 'Manage Inspections'],
                // Sprint 7 Task 59: read access to inspection specs (separate
                // from quality.specs.manage so production roles can browse
                // tolerances without authoring them).
                ['slug' => 'quality.specs.view',           'name' => 'View Inspection Specs'],
                ['slug' => 'quality.specs.manage',         'name' => 'Manage Inspection Specs'],
                ['slug' => 'quality.ncr.view',   'name' => 'View NCRs'],
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
            // Sprint 8 — new permission groups
            'assets' => [
                ['slug' => 'assets.view',                'name' => 'View Assets'],
                ['slug' => 'assets.create',              'name' => 'Create Asset'],
                ['slug' => 'assets.update',              'name' => 'Update Asset'],
                ['slug' => 'assets.delete',              'name' => 'Delete Asset'],
                ['slug' => 'assets.dispose',             'name' => 'Dispose Asset'],
                ['slug' => 'assets.depreciation.view',   'name' => 'View Asset Depreciation'],
                ['slug' => 'assets.depreciation.run',    'name' => 'Run Asset Depreciation'],
            ],
            'hr_separation' => [
                ['slug' => 'hr.separation.view',         'name' => 'View Employee Separations'],
                ['slug' => 'hr.separation.initiate',     'name' => 'Initiate Employee Separation'],
                ['slug' => 'hr.clearance.sign',          'name' => 'Sign Clearance Item'],
                ['slug' => 'hr.separation.finalize',     'name' => 'Finalize Separation & Final Pay'],
            ],
            'dashboards' => [
                ['slug' => 'dashboard.plant_manager.view','name' => 'View Plant Manager Dashboard'],
                ['slug' => 'dashboard.hr.view',           'name' => 'View HR Dashboard'],
                ['slug' => 'dashboard.ppc.view',          'name' => 'View PPC Dashboard'],
                ['slug' => 'dashboard.accounting.view',   'name' => 'View Accounting Dashboard'],
                // D6, D7, D8 — New role-specific dashboards
                ['slug' => 'dashboard.purchasing.view',   'name' => 'View Purchasing Officer Dashboard'],
                ['slug' => 'dashboard.warehouse.view',    'name' => 'View Warehouse Staff Dashboard'],
                ['slug' => 'dashboard.quality.view',       'name' => 'View QC Inspector Dashboard'],
                // Task 2 — System Administrator dashboard
                ['slug' => 'dashboard.admin.view',         'name' => 'View System Administrator Dashboard'],
                // Series C — Task C5
                ['slug' => 'dashboard.view_bottlenecks',  'name' => 'View Chain Bottleneck Widget'],
            ],
            'platform' => [
                ['slug' => 'search.global',                       'name' => 'Use Global Search'],
                ['slug' => 'notifications.view',                  'name' => 'View Own Notifications'],
                ['slug' => 'notifications.preferences.manage',    'name' => 'Manage Own Notification Preferences'],
                // Task A2 — alert engine
                ['slug' => 'alerts.view',                         'name' => 'View Alerts'],
                ['slug' => 'alerts.dismiss',                      'name' => 'Dismiss Alerts'],
                // Series R — Task R4: dashboard layout management.
                ['slug' => 'dashboard.layout.reset',              'name' => 'Reset Own Dashboard Layout to Default'],
                ['slug' => 'dashboard.role_defaults.manage',      'name' => 'Manage Role-Default Dashboard Layouts'],
                // Series F — Task F1 & F2: cross-module aggregator pages.
                ['slug' => 'calendar.view',                       'name' => 'View Company Calendar'],
                ['slug' => 'approvals.board.view',                'name' => 'View Approvals Kanban Board'],
            ],
            // Task A9 — payroll anomaly flags
            'payroll_anomalies' => [
                ['slug' => 'payroll.anomalies.review',            'name' => 'Review Payroll Anomaly Flags'],
            ],

            // ADV11 — Demand & Sales Forecasting
            'forecasting' => [
                ['slug' => 'forecasting.view',   'name' => 'View Demand Forecasts & Stock-Out Projections'],
                ['slug' => 'forecasting.manage', 'name' => 'Recompute / Override Demand Forecasts'],
            ],

            // ADV12 — Return Management (RMA)
            'return_management' => [
                ['slug' => 'return_management.view',   'name' => 'View Return Requests (RMA)'],
                ['slug' => 'return_management.manage', 'name' => 'Create / Approve / Complete Return Requests'],
            ],

            // ADV9 — Budgeting
            'budgeting' => [
                ['slug' => 'budgeting.view',    'name' => 'View Budgets & Reports'],
                ['slug' => 'budgeting.manage',  'name' => 'Create / Edit / Submit Budgets'],
                ['slug' => 'budgeting.approve', 'name' => 'Approve / Reject Budgets & Transfers'],
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
                    $this->module('hr_separation'),
                    $this->module('loans'),
                    $this->selfService(),
                    [
                        'payroll.view',
                        'payroll.payslip.view_all',
                        'payroll.periods.create',
                        'payroll.periods.compute',
                        'payroll.periods.approve',
                        'payroll.adjustments.create',
                        'payroll.thirteenth_month.run',
                        'payroll.anomalies.review',
                        'dashboard.hr.view',
                        'search.global',
                        'notifications.preferences.manage',
                        'alerts.view',
                    ],
                ),
            ],
            'finance_officer' => [
                'name' => 'Finance Officer',
                'description' => 'Manages payroll finalization, accounting, vendor & customer ledgers.',
                'permissions' => array_merge(
                    $this->module('payroll'),
                    $this->module('accounting'),
                    $this->module('budgeting'),
                    $this->module('loans'),
                    $this->module('assets'),
                    $this->selfService(),
                    [
                        'admin.gov_tables.manage', 'dashboard.accounting.view',
                        'search.global', 'notifications.preferences.manage',
                        'hr.profile_updates.finance_review',
                        'payroll.anomalies.review',
                        'alerts.view', 'alerts.dismiss',
                        'dashboard.view_bottlenecks',
                        'purchasing.suppliers.performance.view',
                        'forecasting.view',
                        'return_management.view',
                    ],
                ),
            ],
            'production_manager' => [
                'name' => 'Production Manager',
                'description' => 'Oversees work orders, output, OEE.',
                'permissions' => array_merge(
                    $this->module('production'),
                    $this->selfService(),
                    [
                        'mrp.view', 'mrp.schedule',
                        'inventory.view',
                        // Quality: view + read sub-resources for quality dashboard / NCR/inspection pages
                        'quality.view', 'quality.inspections.view', 'quality.ncr.view',
                        'dashboard.plant_manager.view', 'dashboard.ppc.view',
                        'maintenance.view', 'assets.view',
                        'search.global', 'notifications.preferences.manage',
                        'alerts.view', 'alerts.dismiss',
                        'dashboard.view_bottlenecks',
                        'forecasting.view',
                        'return_management.view',
                    ],
                ),
            ],
            'ppc_head' => [
                'name' => 'PPC Head',
                'description' => 'Production Planning & Control — owns the schedule and BOMs.',
                'permissions' => array_merge(
                    $this->module('mrp'),
                    $this->module('forecasting'),
                    $this->selfService(),
                    [
                        'production.view', 'production.work_orders.view',
                        'production.wo.create', 'production.wo.confirm',
                        'dashboard.ppc.view', 'maintenance.view', 'assets.view',
                        'search.global', 'notifications.preferences.manage',
                        'alerts.view', 'alerts.dismiss',
                        'dashboard.view_bottlenecks',
                        'return_management.view', 'return_management.manage',
                    ],
                ),
            ],
            'purchasing_officer' => [
                'name' => 'Purchasing Officer',
                'description' => 'Manages PRs, POs, vendor relationships.',
                'permissions' => array_merge(
                    $this->module('purchasing'),
                    $this->selfService(),
                    [
                        'inventory.view', 'inventory.grn.create', 'supply_chain.shipments.manage',
                        'accounting.vendors.view', 'accounting.bills.view',
                        'forecasting.view',
                        'return_management.view', 'return_management.manage',
                        'dashboard.purchasing.view',
                    ],
                ),
            ],
            'warehouse_staff' => [
                'name' => 'Warehouse Staff',
                'description' => 'Receives goods, issues materials, counts stock, manages warehouse map.',
                'permissions' => array_merge(
                    $this->selfService(),
                    [
                        'inventory.view',
                        'inventory.items.manage',
                        'inventory.warehouse.manage',
                        'inventory.grn.create',
                        'inventory.issue.create',
                        'inventory.adjust',
                        'inventory.stock_count.view',
                        'inventory.stock_count.manage',
                        'inventory.picking.view',
                        'forecasting.view',
                        'return_management.view',
                        'dashboard.warehouse.view',
                    ],
                ),
            ],
            'qc_inspector' => [
                'name' => 'QC Inspector',
                'description' => 'Logs inspection results, raises NCRs, views RMAs.',
                'permissions' => array_merge(
                    $this->module('quality'),
                    $this->selfService(),
                    [
                        'return_management.view',
                        'dashboard.quality.view',
                    ],
                ),
            ],
            'maintenance_tech' => [
                'name' => 'Maintenance Technician',
                'description' => 'Executes maintenance work orders.',
                'permissions' => array_merge(
                    $this->selfService(),
                    [
                        'maintenance.view', 'maintenance.wo.create', 'maintenance.wo.complete',
                        'assets.view',
                        'search.global', 'notifications.preferences.manage',
                    ],
                ),
            ],
            'impex_officer' => [
                'name' => 'ImpEx Officer',
                'description' => 'Tracks imported shipments and customs documents.',
                'permissions' => array_merge(
                    $this->selfService(),
                    ['supply_chain.view', 'supply_chain.shipments.manage', 'purchasing.view'],
                ),
            ],
            'department_head' => [
                'name' => 'Department Head',
                'description' => 'Approves leaves, OT, PRs for their department.',
                'permissions' => array_merge(
                    $this->selfService(),
                    [
                        'hr.employees.view',
                        'attendance.ot.approve',
                        'leave.approve_dept',
                        'purchasing.view', 'purchasing.pr.approve',
                        'hr.clearance.sign',
                        'search.global', 'notifications.preferences.manage',
                    ],
                ),
            ],
            'employee' => [
                'name' => 'Employee',
                'description' => 'Self-service portal access only.',
                'permissions' => array_merge(
                    $this->selfService(),
                    ['notifications.preferences.manage'],
                ),
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

    /**
     * Permissions every employee-type user needs for the self-service portal
     * (/self-service/dtr, /self-service/leaves, /self-service/loans, /self-service/payslips).
     * Controllers already scope all of these to the authenticated user's employee_id.
     *
     * @return array<int, string>
     */
    private function selfService(): array
    {
        return [
            'attendance.view',      // DTR page → /attendance/attendances (scoped to self)
            'attendance.ot.create', // Overtime page
            'leave.view',           // Leave page
            'leave.create',         // File leave request
            'loans.view',           // Loans list → /hr/self-service/loans + /loans (scoped)
            'loans.create',         // Apply loan + preview amortization
            'payroll.view',         // Payslips → /payroll (scoped to self)
        ];
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
        // Series R — Task R1: every seeded role is a system role and cannot
        // be edited or deleted through the admin UI.
        foreach ($this->roleCatalog() as $slug => $def) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'        => $def['name'],
                    'description' => $def['description'],
                    'is_system'   => true,
                ],
            );

            // Series R — Task R4: every role gets the layout-reset permission
            // by default so users can always restore their dashboard.
            $permissions = $def['permissions'] === '*'
                ? '*'
                : array_values(array_unique(array_merge(
                    (array) $def['permissions'],
                    [
                        'notifications.view',
                        'dashboard.layout.reset',
                        // Series F — Tasks F1, F2, F5: cross-cutting reads
                        // available to every authenticated role.
                        'calendar.view',
                        'approvals.board.view',
                        'hr.directory.view',
                    ],
                )));

            $ids = $permissions === '*'
                ? $allSlugs->values()->all()
                : array_values(array_filter(array_map(
                    fn (string $s) => $allSlugs[$s] ?? null,
                    (array) $permissions,
                )));

            $role->permissions()->sync($ids);
        }

        $this->command?->info('Roles + permissions seeded.');
    }
}
