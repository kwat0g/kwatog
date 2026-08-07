<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Scope cut (2026-08-07) — remove settings that only the deleted SPC
 * control-chart machinery read.
 *
 * Kept: quality.spc.cpk_launch_threshold, cpk_ongoing_threshold,
 * cpk_action_threshold and minimum_capability_samples — SpcService still reads
 * all four to compute and interpret Cp/Cpk.
 */
return new class extends Migration
{
    private const CHART_ONLY_KEYS = [
        'quality.spc.default_subgroup_size',
        'quality.spc.minimum_control_points',
        'quality.spc.recalculate_after_points',
        'quality.spc.recalculate_interval_points',
        'quality.spc.display_history_points',
        'quality.spc.alert_notification_roles',
        'quality.spc.xbar_r_constants',
    ];

    public function up(): void
    {
        DB::table('settings')->whereIn('key', self::CHART_ONLY_KEYS)->delete();
    }

    public function down(): void
    {
        // Values come back with the control-chart migrations (0335, 0362, 0370)
        // if that feature is ever restored from git history.
    }
};
