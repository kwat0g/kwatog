<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (dashboard/DSS) — return-management indexes, Phase 0 B §4 gaps 10-16
 * (B-F12: "returns fact tables nearly unindexed").
 *
 * `return_requests` carried no primary dashboard filter index at all: the
 * returns page filters status/type/date and joins counterparty + order + NCR +
 * inspection + credit-note + replacement-WO references, none of which had an
 * index. `return_request_items` had only the parent link (plus one movement
 * composite) and `return_cases` was missing every resolution-side join.
 *
 * Timestamp-named: the returns tables are also touched by `2026_*` migrations,
 * which always sort after every `0NNN_` file. No silent `hasColumn` guards — a
 * missing dependency must throw (see 2026_10_11_090000).
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => columns to index */
    private const INDEXES = [
        'return_requests' => [
            'status',                // primary dashboard filter
            'type',                  // customer RMA vs supplier return
            'return_date',           // every time-series/funnel window
            'customer_id',           // returns by customer
            'vendor_id',             // returns by supplier
            'sales_order_id',        // return→SO trace
            'purchase_order_id',     // return→PO trace
            'invoice_id',            // return→AR join
            'bill_id',               // return→AP join
            'ncr_id',                // return→NCR loop
            'inspection_id',         // return→QC join
            'replacement_wo_id',     // return→recovery WO
            'credit_note_id',        // return→credit note
            'stock_movement_id',     // return→ledger
        ],
        'return_request_items' => [
            'item_id',                                // raw-material returns
            'product_id',                             // finished-good returns
            'ncr_id',                                 // defect Pareto by NCR
            'source_sales_order_item_id',             // provenance (all six)
            'source_delivery_item_id',
            'source_po_item_id',
            'source_grn_item_id',
            'source_invoice_item_id',
            'source_bill_item_id',
            'quarantine_location_id',                 // quarantine reconciliation
            'quarantine_movement_id',
            'quarantine_release_movement_id',
            'stock_movement_id',
        ],
        'return_cases' => [
            'credit_note_id',                         // case→resolution joins
            'replacement_sales_order_id',
            'replacement_purchase_order_id',
            'replacement_delivery_id',
            'resolution_goods_receipt_note_id',
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
