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

        // Sprint 5 — Purchasing tolerances + auto-replenishment.
        $settings->set('purchasing.three_way_tolerance_qty_pct',   5.0, 'purchasing');
        $settings->set('purchasing.three_way_tolerance_price_pct', 5.0, 'purchasing');
        $settings->set('inventory.allow_negative', false, 'inventory');

        // Module feature toggles — Sprint 5 enables Inventory + Purchasing.
        $modules = [
            'hr' => true,
            'attendance' => true,
            'leave' => true,
            'payroll' => true,
            'loans' => true,
            'accounting' => true,
            'inventory' => true,
            'purchasing' => true,
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
