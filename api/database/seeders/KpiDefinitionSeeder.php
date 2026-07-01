<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Dashboard\Models\KpiDefinition;
use Illuminate\Database\Seeder;

class KpiDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $kpis = [
            ['code' => 'oee',               'name' => 'Overall Equipment Effectiveness', 'module' => 'production',   'unit' => 'percentage', 'direction' => 'higher_is_better', 'target_value' => 85.0000, 'warning_threshold' => 75.0000, 'calculation_method' => 'computeOee',              'display_order' => 1],
            ['code' => 'dppm',              'name' => 'Defective Parts Per Million',     'module' => 'quality',      'unit' => 'count',      'direction' => 'lower_is_better',  'target_value' => 500.0000, 'warning_threshold' => 750.0000, 'calculation_method' => 'computeDppm',            'display_order' => 2],
            ['code' => 'first_pass_yield',  'name' => 'First Pass Yield',                'module' => 'quality',      'unit' => 'percentage', 'direction' => 'higher_is_better', 'target_value' => 98.0000, 'warning_threshold' => 95.0000, 'calculation_method' => 'computeFirstPassYield',   'display_order' => 3],
            ['code' => 'on_time_delivery',  'name' => 'On-Time Delivery Rate',           'module' => 'supply_chain', 'unit' => 'percentage', 'direction' => 'higher_is_better', 'target_value' => 95.0000, 'warning_threshold' => 90.0000, 'calculation_method' => 'computeOnTimeDelivery',   'display_order' => 4],
            ['code' => 'supplier_quality',  'name' => 'Supplier Quality Score',          'module' => 'purchasing',   'unit' => 'percentage', 'direction' => 'higher_is_better', 'target_value' => 90.0000, 'warning_threshold' => 80.0000, 'calculation_method' => 'computeSupplierQuality',  'display_order' => 5],
            ['code' => 'copq_pct_revenue',  'name' => 'COPQ as % of Revenue',            'module' => 'quality',      'unit' => 'percentage', 'direction' => 'lower_is_better',  'target_value' => 2.0000, 'warning_threshold' => 3.0000, 'calculation_method' => 'computeCopqPctRevenue',    'display_order' => 6],
            ['code' => 'attendance_rate',   'name' => 'Attendance Rate',                 'module' => 'attendance',   'unit' => 'percentage', 'direction' => 'higher_is_better', 'target_value' => 96.0000, 'warning_threshold' => 93.0000, 'calculation_method' => 'computeAttendanceRate',   'display_order' => 7],
            ['code' => 'ar_aging_60d',      'name' => 'AR Over 60 Days',                 'module' => 'accounting',   'unit' => 'percentage', 'direction' => 'lower_is_better',  'target_value' => 5.0000, 'warning_threshold' => 8.0000, 'calculation_method' => 'computeArAging60d',        'display_order' => 8],
            ['code' => 'budget_utilization','name' => 'Budget Utilization',               'module' => 'accounting',   'unit' => 'percentage', 'direction' => 'higher_is_better', 'target_value' => 90.0000, 'warning_threshold' => 80.0000, 'calculation_method' => 'computeBudgetUtilization','display_order' => 9],
            ['code' => 'ncr_closure_days',  'name' => 'Avg NCR Closure Time',            'module' => 'quality',      'unit' => 'days',       'direction' => 'lower_is_better',  'target_value' => 5.0000, 'warning_threshold' => 8.0000, 'calculation_method' => 'computeNcrClosureDays',    'display_order' => 10],
            ['code' => 'inventory_turnover','name' => 'Inventory Turnover',               'module' => 'inventory',    'unit' => 'ratio',      'direction' => 'higher_is_better', 'target_value' => 6.0000, 'warning_threshold' => 4.0000, 'calculation_method' => 'computeInventoryTurnover','display_order' => 11],
            ['code' => 'wo_completion_rate','name' => 'WO Completion Rate',               'module' => 'production',   'unit' => 'percentage', 'direction' => 'higher_is_better', 'target_value' => 95.0000, 'warning_threshold' => 90.0000, 'calculation_method' => 'computeWoCompletionRate','display_order' => 12],
        ];

        foreach ($kpis as $kpi) {
            KpiDefinition::updateOrCreate(
                ['code' => $kpi['code']],
                $kpi,
            );
        }

        $this->command?->info('KPI definitions seeded (' . count($kpis) . ').');
    }
}
