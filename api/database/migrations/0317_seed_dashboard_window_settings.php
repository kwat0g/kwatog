<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['dashboard.widgets.gantt_horizon_days', 7, 'Dashboard Gantt Horizon Days'],
            ['dashboard.widgets.payables_horizon_days', 30, 'Dashboard Payables Horizon Days'],
            ['dashboard.widgets.probation_horizon_days', 30, 'Dashboard Probation Horizon Days'],
            ['dashboard.widgets.delivery_horizon_days', 7, 'Dashboard Delivery Horizon Days'],
        ] as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'dashboard', 'label' => $label,
                'description' => 'Time window used by dashboard widgets.', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'dashboard.widgets.gantt_horizon_days', 'dashboard.widgets.payables_horizon_days',
            'dashboard.widgets.probation_horizon_days', 'dashboard.widgets.delivery_horizon_days',
        ])->delete();
    }
};
