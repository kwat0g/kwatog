<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ROWS = [
        ['approvals.recent_history_days', 30, 'Recent Approval History (Days)', 'approval'],
        ['dashboard.kpi_snapshot_lookback_days', 60, 'KPI Snapshot Lookback (Days)', 'dashboard'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $label, $group]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => $group,
                'label' => $label, 'description' => 'Runtime reporting window.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
