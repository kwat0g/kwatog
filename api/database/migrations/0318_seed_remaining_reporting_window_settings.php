<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['inventory.dashboard.consumption_history_days', 30, 'Inventory Consumption History Days'],
            ['dashboard.finance.payroll_pipeline_history_days', 90, 'Finance Payroll Pipeline History Days'],
            ['maintenance.downtime.default_history_days', 30, 'Downtime Analytics History Days'],
            ['leave.request.past_window_days', 30, 'Leave Request Past Window Days'],
            ['leave.request.future_window_days', 365, 'Leave Request Future Window Days'],
        ] as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => explode('.', $key, 2)[0], 'label' => $label,
                'description' => 'Default date window used by the live module workflow.', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'inventory.dashboard.consumption_history_days', 'dashboard.finance.payroll_pipeline_history_days',
            'maintenance.downtime.default_history_days', 'leave.request.past_window_days', 'leave.request.future_window_days',
        ])->delete();
    }
};
