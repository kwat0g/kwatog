<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Threshold for AlertEngineService::checkScheduler().
 *
 * 30 minutes, not 15: `alerts:run` fires every 15, so 30 allows one missed
 * tick of slack before a scheduler that is merely slow is reported as stalled.
 */
return new class extends Migration
{
    private const ROWS = [
        ['alerts.scheduler.stale_minutes', 30, 'alerts', 'Scheduler Stale Minutes', 'Minutes a scheduler tick may run or sit finished before a critical scheduler alert is raised.'],
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
