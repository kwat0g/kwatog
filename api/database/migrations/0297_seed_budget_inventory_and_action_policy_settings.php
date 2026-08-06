<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['budget.warning_ratio', 0.80, 'budget', 'Budget Warning Ratio'], ['budget.critical_ratio', 0.95, 'budget', 'Budget Critical Ratio'],
        ['budget.exhausted_ratio', 1.00, 'budget', 'Budget Exhausted Ratio'], ['budget.overdrawn_ratio', 1.20, 'budget', 'Budget Overdrawn Ratio'],
        ['inventory.abc.a_ratio', 0.70, 'inventory', 'ABC A-class Cumulative Ratio'], ['inventory.abc.b_ratio', 0.90, 'inventory', 'ABC B-class Cumulative Ratio'],
        ['inventory.replenishment.demand_history_days', 30, 'inventory', 'Replenishment Demand History Days'], ['inventory.replenishment.coverage_buffer_ratio', 1.20, 'inventory', 'Replenishment Coverage Buffer Ratio'],
        ['inventory.stockout.demand_history_days', 30, 'inventory', 'Stock-out Demand History Days'], ['inventory.stockout.forecast_period_days', 30, 'inventory', 'Stock-out Forecast Period Days'], ['inventory.stockout.coverage_buffer_ratio', 1.20, 'inventory', 'Stock-out Coverage Buffer Ratio'], ['inventory.stockout.zero_lead_reorder_ratio', 0.50, 'inventory', 'Stock-out Zero-lead Reorder Ratio'], ['inventory.stockout.high_risk_buffer_days', 7, 'inventory', 'Stock-out High-risk Buffer Days'], ['inventory.stockout.medium_risk_days', 30, 'inventory', 'Stock-out Medium-risk Days'],
        ['production.dashboard.mold_warning_ratio', 0.80, 'production', 'Production Dashboard Mold Warning Ratio'],
        ['action_center.default_sla_hours', 24, 'action_center', 'Default Action Center SLA Hours'],
        ['action_center.maintenance.critical_sla_hours', 8, 'action_center', 'Critical Maintenance SLA Hours'], ['action_center.maintenance.default_sla_hours', 72, 'action_center', 'Default Maintenance SLA Hours'],
        ['action_center.production.critical_sla_hours', 48, 'action_center', 'Critical Production SLA Hours'], ['action_center.production.default_sla_hours', 72, 'action_center', 'Default Production SLA Hours'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label]) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'value' => json_encode($value), 'group' => $group, 'label' => $label, 'description' => 'Configurable ERP policy value.', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
