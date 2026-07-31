<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['purchasing.supplier_score.weight_on_time', 0.25, 'purchasing', 'Supplier Score On-time Weight'],
        ['purchasing.supplier_score.weight_quality', 0.35, 'purchasing', 'Supplier Score Quality Weight'],
        ['purchasing.supplier_score.weight_ncr', 0.10, 'purchasing', 'Supplier Score NCR Weight'],
        ['purchasing.supplier_score.weight_price', 0.15, 'purchasing', 'Supplier Score Price Weight'],
        ['purchasing.supplier_score.weight_lead_time', 0.15, 'purchasing', 'Supplier Score Lead-time Weight'],
        ['purchasing.supplier_score.neutral_missing_metric', 50, 'purchasing', 'Supplier Score Missing-metric Value'],
        ['purchasing.supplier_score.ncr_penalty_factor', 2, 'purchasing', 'Supplier NCR Penalty Factor'],
        ['purchasing.supplier_score.price_penalty_factor', 2, 'purchasing', 'Supplier Price Penalty Factor'],
        ['purchasing.supplier_score.lead_time_penalty_factor', 5, 'purchasing', 'Supplier Lead-time Penalty Factor'],
        ['purchasing.supplier_score.tier_a_min', 90, 'purchasing', 'Supplier Tier A Minimum'],
        ['purchasing.supplier_score.tier_b_min', 75, 'purchasing', 'Supplier Tier B Minimum'],
        ['purchasing.supplier_score.tier_c_min', 60, 'purchasing', 'Supplier Tier C Minimum'],
        ['purchasing.supplier_score.deterioration_drop', 20, 'purchasing', 'Supplier Deterioration Alert Drop'],
        ['hr.training_expiry.t30_days', 30, 'hr', 'Training First Reminder Days'],
        ['hr.training_expiry.t14_days', 14, 'hr', 'Training Second Reminder Days'],
        ['hr.training_expiry.t7_days', 7, 'hr', 'Training Urgent Reminder Days'],
        ['quality.ncr.sla_critical_hours', 8, 'quality', 'Critical NCR SLA Hours'],
        ['quality.ncr.sla_high_hours', 24, 'quality', 'High NCR SLA Hours'],
        ['quality.ncr.sla_medium_hours', 72, 'quality', 'Medium NCR SLA Hours'],
        ['quality.ncr.sla_low_hours', 168, 'quality', 'Low NCR SLA Hours'],
        ['quality.ppap.approval_validity_years', 3, 'quality', 'PPAP Approval Validity Years'],
        ['crm.complaint_8d.d3_due_hours', 48, 'crm', '8D D3 Due Hours'],
        ['crm.complaint_8d.d4_due_days', 7, 'crm', '8D D4 Due Days'],
        ['crm.complaint_8d.finalize_due_days', 30, 'crm', '8D Finalization Due Days'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => $group,
                'label' => $label, 'description' => 'Configurable ERP policy value.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
