<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Services\SettingsService;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);

        $rows = [
            // ── Company ──────────────────────────────────────
            [
                'key'         => 'company.name',
                'value'       => 'Philippine Ogami Corporation',
                'group'       => 'company',
                'label'       => 'Company Name',
                'description' => 'Legal entity name shown on invoices, reports, and official documents.',
            ],
            [
                'key'         => 'company.address',
                'value'       => 'FCIE Special Economic Zone, Dasmariñas, Cavite, Philippines',
                'group'       => 'company',
                'label'       => 'Company Address',
                'description' => 'Registered office address printed on official documents.',
            ],
            [
                'key'         => 'company.tin',
                'value'       => '000-000-000-000',
                'group'       => 'company',
                'label'       => 'Tax Identification Number',
                'description' => 'TIN used on BIR forms, invoices, and withholding certificates.',
            ],

            // ── Fiscal ───────────────────────────────────────
            [
                'key'         => 'fiscal.year_start_month',
                'value'       => 1,
                'group'       => 'fiscal',
                'label'       => 'Fiscal Year Start Month',
                'description' => 'Month number (1–12) when your fiscal year begins.',
            ],

            // ── Payroll ──────────────────────────────────────
            [
                'key'         => 'payroll.schedule',
                'value'       => 'semi_monthly',
                'group'       => 'payroll',
                'label'       => 'Payroll Schedule',
                'description' => 'Pay frequency — semi-monthly, monthly, or weekly.',
            ],
            [
                'key'         => 'payroll.cutoff.first_half',
                'value'       => 15,
                'group'       => 'payroll',
                'label'       => 'First Half Cutoff Day',
                'description' => 'Day of the month for the first payroll cutoff.',
            ],
            [
                'key'         => 'payroll.cutoff.second_half',
                'value'       => 31,
                'group'       => 'payroll',
                'label'       => 'Second Half Cutoff Day',
                'description' => 'Day of the month for the second payroll cutoff.',
            ],
            [
                'key'         => 'payroll.payslip_email.enabled',
                'value'       => true,
                'group'       => 'payroll',
                'label'       => 'Email Payslips',
                'description' => 'Automatically email payslip PDFs to employees after payroll finalization.',
            ],

            // ── Approvals ────────────────────────────────────
            [
                'key'         => 'approval.po.vp_threshold',
                'value'       => 50000,
                'group'       => 'approval',
                'label'       => 'VP Approval Threshold (₱)',
                'description' => 'Purchase order amounts above this require VP-level approval.',
            ],
            [
                'key'         => 'approvals.auto_resolve.enabled',
                'value'       => false,
                'group'       => 'approval',
                'label'       => 'Auto-resolve Stale Approvals',
                'description' => 'Automatically approve or reject pending approvals after the SLA deadline.',
            ],
            [
                'key'         => 'approvals.auto_resolve.default_hours',
                'value'       => 72,
                'group'       => 'approval',
                'label'       => 'Auto-resolve Deadline (hours)',
                'description' => 'Hours after submission before a pending approval is auto-resolved.',
            ],
            [
                'key'         => 'approvals.auto_resolve.default_action',
                'value'       => 'reject',
                'group'       => 'approval',
                'label'       => 'Auto-resolve Action',
                'description' => 'Action taken when the auto-resolve deadline passes — approve or reject.',
            ],

            // ── Accounting ───────────────────────────────────
            [
                'key'         => 'accounting.default_sales_revenue_account_code',
                'value'       => '4010',
                'group'       => 'accounting',
                'label'       => 'Default Sales Revenue Account',
                'description' => 'Chart of accounts code for auto-generated invoice revenue entries (e.g. 4010 = Sales Revenue).',
            ],
            [
                'key'         => 'accounting.ar_dunning.enabled',
                'value'       => true,
                'group'       => 'accounting',
                'label'       => 'AR Dunning Emails',
                'description' => 'Send automated overdue invoice reminder emails to customers.',
            ],
            [
                'key'         => 'accounting.ar_dunning.tier_days_csv',
                'value'       => '7,15,30',
                'group'       => 'accounting',
                'label'       => 'Dunning Tier Days',
                'description' => 'Comma-separated days after due date for each dunning tier (e.g. 7,15,30).',
            ],

            // ── Attendance ───────────────────────────────────
            [
                'key'         => 'attendance.auto_ot_detect.enabled',
                'value'       => true,
                'group'       => 'attendance',
                'label'       => 'Auto-detect Overtime',
                'description' => 'Automatically detect overtime from biometric punch data.',
            ],
            [
                'key'         => 'attendance.auto_ot_detect.threshold_minutes',
                'value'       => 30,
                'group'       => 'attendance',
                'label'       => 'OT Detection Threshold (minutes)',
                'description' => 'Minimum minutes beyond shift end before overtime is counted.',
            ],

            // ── HR ───────────────────────────────────────────
            [
                'key'         => 'hr.auto_provision_user.enabled',
                'value'       => true,
                'group'       => 'hr',
                'label'       => 'Auto-provision User on Hire',
                'description' => 'Automatically create a user account when a new employee is hired.',
            ],

            // ── Purchasing ───────────────────────────────────
            [
                'key'         => 'purchasing.three_way_tolerance_qty_pct',
                'value'       => 5.0,
                'group'       => 'purchasing',
                'label'       => '3-Way Match Qty Tolerance (%)',
                'description' => 'Percentage tolerance for quantity mismatch in PO/GRN/Invoice 3-way matching.',
            ],
            [
                'key'         => 'purchasing.three_way_tolerance_price_pct',
                'value'       => 5.0,
                'group'       => 'purchasing',
                'label'       => '3-Way Match Price Tolerance (%)',
                'description' => 'Percentage tolerance for price mismatch in PO/GRN/Invoice 3-way matching.',
            ],

            // ── Inventory ────────────────────────────────────
            [
                'key'         => 'inventory.allow_negative',
                'value'       => false,
                'group'       => 'inventory',
                'label'       => 'Allow Negative Stock',
                'description' => 'Permit issuing items even when warehouse stock would go below zero.',
            ],
            [
                'key'         => 'inventory.safety_stock.enabled',
                'value'       => true,
                'group'       => 'inventory',
                'label'       => 'Auto Safety Stock',
                'description' => 'Automatically recompute safety stock levels based on demand history.',
            ],
            [
                'key'         => 'inventory.safety_stock.service_level_z',
                'value'       => 1.65,
                'group'       => 'inventory',
                'label'       => 'Service Level Z-score',
                'description' => 'Z-score for desired service level (1.65 ≈ 95%, 2.33 ≈ 99%).',
            ],
            [
                'key'         => 'inventory.safety_stock.history_days',
                'value'       => 90,
                'group'       => 'inventory',
                'label'       => 'Demand History Window (days)',
                'description' => 'Number of days of consumption history used for safety stock calculation.',
            ],
            [
                'key'         => 'inventory.safety_stock.min_demand_days',
                'value'       => 14,
                'group'       => 'inventory',
                'label'       => 'Minimum Demand Days',
                'description' => 'Minimum days of demand data required before safety stock is calculated.',
            ],

            // ── Security ─────────────────────────────────────
            [
                'key'         => 'security.max_login_attempts',
                'value'       => 5,
                'group'       => 'security',
                'label'       => 'Max Login Attempts',
                'description' => 'Account locks after this many consecutive failed login attempts.',
            ],
            [
                'key'         => 'security.lockout_minutes',
                'value'       => 15,
                'group'       => 'security',
                'label'       => 'Lockout Duration (minutes)',
                'description' => 'How long a locked account remains locked before automatic unlock.',
            ],
            [
                'key'         => 'security.password_history_depth',
                'value'       => 3,
                'group'       => 'security',
                'label'       => 'Password History Depth',
                'description' => 'Number of previous passwords that cannot be reused when changing password.',
            ],
            [
                'key'         => 'security.password_min_length',
                'value'       => 8,
                'group'       => 'security',
                'label'       => 'Minimum Password Length',
                'description' => 'Minimum number of characters required for new passwords.',
            ],
            [
                'key'         => 'security.session_timeout_employee',
                'value'       => 15,
                'group'       => 'security',
                'label'       => 'Session Timeout — Employee (minutes)',
                'description' => 'Idle session timeout for users with the employee role.',
            ],
            [
                'key'         => 'security.session_timeout_default',
                'value'       => 30,
                'group'       => 'security',
                'label'       => 'Session Timeout — Default (minutes)',
                'description' => 'Idle session timeout for all users except employees.',
            ],
            [
                'key'         => 'security.password_expiry_days',
                'value'       => 90,
                'group'       => 'security',
                'label'       => 'Password Expiry (days)',
                'description' => 'Days before a password must be changed. Set to 0 to disable expiry.',
            ],
        ];

        foreach ($rows as $row) {
            $settings->set(
                $row['key'],
                $row['value'],
                $row['group'],
                $row['label'],
                $row['description'],
            );
        }

        // Module feature toggles
        $modules = [
            'hr'                => ['Human Resources',     'Employee records, departments, positions, separation, and clearance.'],
            'attendance'        => ['Attendance',           'Shifts, daily time records, overtime, and biometric import.'],
            'leave'             => ['Leave Management',     'Leave types, balances, requests, and approval workflows.'],
            'payroll'           => ['Payroll',              'Payroll engine, government deductions, payslips, and bank files.'],
            'loans'             => ['Loans',                'Company loans, cash advances, and automatic payroll deduction.'],
            'accounting'        => ['Accounting',           'Chart of accounts, journal entries, AP/AR, and financial statements.'],
            'inventory'         => ['Inventory',            'Items, warehouses, goods received, stock issues, and valuation.'],
            'purchasing'        => ['Purchasing',           'Purchase requests, purchase orders, approval chains, and 3-way match.'],
            'crm'               => ['CRM',                 'Customers, price agreements, sales orders, and complaint management.'],
            'mrp'               => ['MRP / MRP II',        'Bills of material, material planning, capacity planning, and molds.'],
            'production'        => ['Production',           'Work orders, machine output, downtime tracking, and OEE.'],
            'supply_chain'      => ['Supply Chain',         'Shipments, import documents, fleet management, and deliveries.'],
            'quality'           => ['Quality',              'Inspection specs, QC results, NCRs, and certificates of conformance.'],
            'maintenance'       => ['Maintenance',          'Preventive schedules, work orders, and mold shot tracking.'],
            'assets'            => ['Assets',               'Fixed asset register, depreciation schedules, and QR tracking.'],
            'search'            => ['Global Search',        'Full-text search across all modules.'],
            'notifications'     => ['Notifications',        'In-app and email notifications for events and approvals.'],
            'recruitment'       => ['Recruitment',          'Job postings, applications, interviews, and hiring workflow.'],
            'return_management' => ['Return Management',    'RMA requests, return processing, and credit memos.'],
            'b2b_portals'       => ['B2B Portals',          'Supplier and customer self-service portals.'],
            'forecasting'       => ['Forecasting',          'Demand forecasts, stock-out projections, and forecast accuracy.'],
            'budgeting'         => ['Budgeting',            'Budgets, budget line items, revisions, and transfers.'],
        ];

        foreach ($modules as $slug => [$label, $description]) {
            $settings->set(
                "modules.{$slug}",
                true,
                'modules',
                $label,
                $description,
            );
        }

        $this->command?->info('Settings + feature toggles seeded.');
    }
}
