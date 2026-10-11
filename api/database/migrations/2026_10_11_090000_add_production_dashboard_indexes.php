<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (dashboard/DSS) — production fact-table indexes, Phase 0 B §4 gaps
 * 2-9 and 44. `wo_operations` had NO non-PK index at all, so the operation→WO
 * join (OEE, WIP boards, operation status) and the machine/mold/operator load
 * reads were sequential scans; `production_logs` had none either. `work_orders`
 * carried a status/planned_start index but nothing for product mix, mold load,
 * subassembly rollup or the actual_start/actual_end pair every lead-time
 * aggregate needs.
 *
 * Column names verified against `information_schema` on the live schema before
 * writing (each target column exists; each index below is absent from
 * `pg_indexes`). Timestamp-named on purpose: these tables are also touched by
 * `2026_*` migrations, and the migrator sorts `0NNN_` before every `2026_*`
 * file — a numbered name here could run before a dependency.
 *
 * Deliberately NO `Schema::hasTable/hasColumn` guards: an index that silently
 * does not get created is indistinguishable from a healthy `migrate:fresh`
 * (CLAUDE.md records three constraints skipped exactly that way), so a missing
 * dependency must throw here instead. `Phase0IndexMigrationTest` asserts every
 * index in this batch actually exists afterwards.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => columns to index */
    private const INDEXES = [
        'wo_operations' => [
            'work_order_id',          // THE operation→WO join (OEE, WIP)
            'status',                 // operation-status boards
            'machine_id',             // machine load
            'mold_id',                // mold usage
            'operator_id',            // operator productivity
            'routing_operation_id',   // routing↔execution comparison
        ],
        'production_logs' => [
            'wo_operation_id',        // OEE / downtime event joins
            'operator_id',            // operator productivity
        ],
        'work_orders' => [
            'product_id',             // product mix / defect Pareto by product
            'mold_id',                // mold shot / load dashboards
            'parent_wo_id',           // subassembly tree rollups
            'actual_start',           // WO lead time
            'actual_end',             // WO throughput / completion trends
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropIndex($table.'_'.$column.'_index');
                }
            });
        }
    }
};
