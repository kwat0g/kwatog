<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['fiscal.year_start_month', 1, 'fiscal', 'Fiscal Year Start Month', 'Month number when the fiscal year begins.'],
        ['accounting.ar_dunning.enabled', true, 'accounting', 'AR Dunning Emails', 'Send automated overdue invoice reminder emails to customers.'],
        ['accounting.ar_dunning.tier_days_csv', '7,15,30', 'accounting', 'Dunning Tier Days', 'Comma-separated days after due date for each dunning tier.'],
        ['accounting.default_sales_revenue_account_code', '4010', 'accounting', 'Default Sales Revenue Account', 'Revenue account code used when a delivered product has no explicit revenue account.'],
        ['hr.auto_provision_user.enabled', true, 'hr', 'Auto-provision User on Hire', 'Automatically create a user account when a new employee is hired.'],
        ['payroll.payslip_email.enabled', true, 'payroll', 'Email Payslips', 'Automatically email payslip PDFs after payroll finalization.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => $group,
                'label' => $label, 'description' => $description,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
