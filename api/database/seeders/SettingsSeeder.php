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

        // Company
        $settings->set('company.name',    'Philippine Ogami Corporation', 'company');
        $settings->set('company.address', 'FCIE Special Economic Zone, Dasmariñas, Cavite, Philippines', 'company');
        $settings->set('company.tin',     '000-000-000-000', 'company');

        // Fiscal
        $settings->set('fiscal.year_start_month', 1, 'fiscal');

        // Payroll
        $settings->set('payroll.schedule',           'semi_monthly', 'payroll');
        $settings->set('payroll.cutoff.first_half',  15,             'payroll');
        $settings->set('payroll.cutoff.second_half', 31,             'payroll');
        $settings->set('payroll.payslip_email.enabled', true, 'payroll');

        // Approval thresholds
        $settings->set('approval.po.vp_threshold', 50000, 'approval');

        // C-1 — Default revenue account for auto-generated invoices on delivery
        // confirm. Falls back via SettingsService->get(
        //   'accounting.default_sales_revenue_account_code', '4010'
        // ). Code 4010 ('Sales Revenue') exists in ChartOfAccountsSeeder; 4000 is
        // the parent group ('Revenue') and is not a postable account.
        $settings->set('accounting.default_sales_revenue_account_code', '4010', 'accounting');

        // T1.1 — Auto-detect OT from biometric punches.
        $settings->set('attendance.auto_ot_detect.enabled',           true, 'attendance');
        $settings->set('attendance.auto_ot_detect.threshold_minutes', 30,   'attendance');

        // T1.3 — Auto-provision User account on Employee hire.
        $settings->set('hr.auto_provision_user.enabled', true, 'hr');

        // Sprint 5 — Purchasing tolerances + auto-replenishment.
        $settings->set('purchasing.three_way_tolerance_qty_pct',   5.0, 'purchasing');
        $settings->set('purchasing.three_way_tolerance_price_pct', 5.0, 'purchasing');
        $settings->set('inventory.allow_negative', false, 'inventory');

        // T1.4 — Demand-driven safety-stock auto-recompute.
        $settings->set('inventory.safety_stock.enabled',          true, 'inventory');
        $settings->set('inventory.safety_stock.service_level_z',  1.65, 'inventory');
        $settings->set('inventory.safety_stock.history_days',     90,   'inventory');
        $settings->set('inventory.safety_stock.min_demand_days',  14,   'inventory');

        // T1.5 — AR dunning auto-emails.
        $settings->set('accounting.ar_dunning.enabled',       true,     'accounting');
        $settings->set('accounting.ar_dunning.tier_days_csv', '7,15,30','accounting');

        // T1.6 — Approval SLA auto-resolve.
        $settings->set('approvals.auto_resolve.enabled',        false,    'approvals');
        $settings->set('approvals.auto_resolve.default_hours',  72,       'approvals');
        $settings->set('approvals.auto_resolve.default_action', 'reject', 'approvals');

        // Module feature toggles — Sprint 6 enables CRM + MRP + Production
        // (Order-to-Cash chain: sales orders → MRP plans → work orders → output → OEE).
        $modules = [
            'hr' => true,
            'attendance' => true,
            'leave' => true,
            'payroll' => true,
            'loans' => true,
            'accounting' => true,
            'inventory' => true,
            'purchasing' => true,
            'crm' => true,
            'mrp' => true,
            'production' => true,
            'supply_chain' => true,  // Sprint 7 Task 65
            'quality' => true,       // Sprint 7 Task 59 enables inspection specs
            'maintenance' => true,   // Sprint 8 Task 69
            'assets'      => true,   // Sprint 8 Task 70
            'search'      => true,   // Sprint 8 Task 75
            'notifications' => true, // Sprint 8 Task 77
            'recruitment'   => true, // Recruitment module
        ];

        foreach ($modules as $slug => $enabled) {
            $settings->set("modules.{$slug}", $enabled, 'modules');
        }

        $this->command?->info('Settings + feature toggles seeded.');
    }
}
