<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['approval.po.vp_threshold', 50000, 'approval', 'PO VP Approval Threshold', 'Purchase orders at or above this amount require VP approval.'],
        ['attendance.auto_ot_detect.enabled', true, 'attendance', 'Auto-detect Overtime', 'Automatically detect overtime from biometric punch data.'],
        ['attendance.auto_ot_detect.threshold_minutes', 30, 'attendance', 'OT Detection Threshold (minutes)', 'Minimum minutes beyond shift end before overtime is counted.'],
        ['purchasing.three_way_tolerance_qty_pct', 5.0, 'purchasing', '3-Way Match Qty Tolerance (%)', 'Percentage tolerance for quantity mismatch in PO/GRN/Invoice 3-way matching.'],
        ['purchasing.three_way_tolerance_price_pct', 5.0, 'purchasing', '3-Way Match Price Tolerance (%)', 'Percentage tolerance for price mismatch in PO/GRN/Invoice 3-way matching.'],
        ['inventory.safety_stock.enabled', true, 'inventory', 'Auto Safety Stock', 'Automatically recompute safety stock levels based on demand history.'],
        ['inventory.safety_stock.service_level_z', 1.65, 'inventory', 'Service Level Z-score', 'Z-score used to calculate the configured inventory service level.'],
        ['inventory.safety_stock.history_days', 90, 'inventory', 'Demand History Window (days)', 'Number of days of consumption history used for safety stock calculation.'],
        ['inventory.safety_stock.min_demand_days', 14, 'inventory', 'Minimum Demand Days', 'Minimum days of demand data required before safety stock is calculated.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => $group,
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
