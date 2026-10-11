<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (dashboard/DSS) — receiving/QC/maintenance join indexes, Phase 0 B §4
 * gaps 1, 31-33, 45.
 *
 * `grn_items.goods_receipt_note_id` is the line→header join behind the pending-
 * GRN queue (`pending_grn_items` style widgets join lines back to a receipt that
 * already carries status); only `item_id` and `purchase_order_item_id` were
 * indexed. `inspections` had stage/status/product and the three single-live
 * uniques, but none of the item / quality-plan / WO-operation joins an incoming
 * or in-process coverage panel needs, and `started_at` (inspection lead time)
 * was missing while `completed_at` was indexed. `maintenance_work_orders.
 * started_at` is the MTTR clock — `completed_at` was indexed alone, so MTTR
 * could not be computed without a scan.
 *
 * Column existence verified against the live schema; none of these indexes
 * exist today. Timestamp-named (tables also touched by `2026_*` migrations).
 * No silent `hasColumn` guards — a missing dependency must throw, not skip
 * (see the note in `2026_10_11_090000_add_production_dashboard_indexes`).
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => columns to index */
    private const INDEXES = [
        'grn_items' => [
            'goods_receipt_note_id',   // line→header join (receiving queue)
        ],
        'inspections' => [
            'item_id',                    // incoming QC by item
            'item_quality_plan_id',       // coverage vs the item's plan
            'wo_operation_id',            // in-process QC by operation
            'inspection_spec_id',         // spec coverage
            'inspection_spec_revision_id', // revision adoption
            'started_at',                 // inspection lead time
        ],
        'maintenance_work_orders' => [
            'started_at',              // MTTR (completed_at was indexed alone)
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
