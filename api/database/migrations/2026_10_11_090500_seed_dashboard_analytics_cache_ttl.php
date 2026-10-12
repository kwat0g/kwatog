<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 (dashboard/DSS) — default TTL for the analytics layer.
 *
 * Every bespoke dashboard caches at 30s per user; the widget-data and rich
 * payload paths (F R15) cache nothing at all, so every 30-60s poll re-runs the
 * aggregation against transactional tables. The analytics layer caches each
 * metric per scope+filters. 60s matches the bespoke-page poll and the badge TTL,
 * and a metric that needs longer declares its own `cacheTtlSeconds` in the
 * definition (the setting is only the default for `live` metrics).
 *
 * Settings-driven rather than a constant because the DB/table-size trade-off
 * changes as the demo dataset grows (and unlike a code constant it can be tuned
 * on staging without a deploy).
 */
return new class extends Migration
{
    private const ROWS = [
        [
            'dashboard.analytics.cache_ttl_seconds',
            60,
            'dashboard',
            'Analytics Cache TTL (seconds)',
            'Default lifetime of a cached analytics metric payload. Applied to metrics whose definition does not declare its own TTL. Scoped by permission/scope/filters, so a longer TTL only delays freshness, never mixes viewers.',
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
