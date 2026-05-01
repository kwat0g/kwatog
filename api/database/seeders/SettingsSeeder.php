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

        // Approval thresholds
        $settings->set('approval.po.vp_threshold', 50000, 'approval');

        // Module feature toggles — Semester 1: HR/Attendance/Leave/Payroll/Loans/Accounting on, ops off.
        $modules = [
            'hr' => true,
            'attendance' => true,
            'leave' => true,
            'payroll' => true,
            'loans' => true,
            'accounting' => true,
            'inventory' => false,
            'purchasing' => false,
            'supply_chain' => false,
            'production' => false,
            'mrp' => false,
            'crm' => false,
            'quality' => false,
            'maintenance' => false,
        ];

        foreach ($modules as $slug => $enabled) {
            $settings->set("modules.{$slug}", $enabled, 'modules');
        }

        $this->command?->info('Settings + feature toggles seeded.');
    }
}
