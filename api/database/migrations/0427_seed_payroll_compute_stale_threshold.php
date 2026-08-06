<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Threshold after which a payroll period wedged at Processing is presumed dead
 * and may be reclaimed by the next Compute click (or the hourly
 * payroll:reap-stale-runs sweep).
 *
 * Must stay above ProcessPayrollJob::TIMEOUT_SECONDS (1800s / 30 min) or a
 * healthy long run could be reclaimed out from under its own worker.
 * PayrollPeriodService::staleAfterMinutes() floors it there regardless of what
 * is stored, so a bad value degrades safely instead of corrupting a live run.
 */
return new class extends Migration
{
    private const ROWS = [
        [
            'payroll.compute.stale_after_minutes',
            45,
            'payroll',
            'Payroll Compute Stale Threshold (minutes)',
            'Minutes after which a payroll period stuck at Processing is treated as a crashed run and may be recomputed. Must exceed the 30-minute compute job timeout.',
        ],
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
