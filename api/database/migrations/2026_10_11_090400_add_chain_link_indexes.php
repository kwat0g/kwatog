<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (dashboard/DSS) — chain-link indexes, Phase 0 B §4 gaps 17-24, 43, 85.
 *
 * The three chains connect by FK/column convention (B-F5/F8): SO→invoice,
 * delivery→invoice, invoice→official receipt, GRN→QC, supplier-invoice→PO,
 * NCR→replacement output. Dashboards walk those links to build funnels, and the
 * OTD metric needs `deliveries.delivered_at/confirmed_at/received_at` — only
 * `scheduled_date` was indexed, so "on-time delivery" could not be filtered.
 * `deliveries` also lacked vehicle/driver indexes for fleet utilization.
 *
 * Timestamp-named (these tables are also touched by `2026_*` migrations). No
 * silent `hasColumn` guards — see 2026_10_11_090000.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => columns to index */
    private const INDEXES = [
        'deliveries' => [
            'delivered_at',      // OTD
            'confirmed_at',      // customer-confirm lead time
            'received_at',       // delivery lead time
            'vehicle_id',        // fleet utilization
            'driver_id',         // driver utilization (excluded from audit class:
                                 // this column is grouped by, not just stamped)
            'invoice_id',        // delivery→invoice reverse lookup
        ],
        'invoices' => [
            'sales_order_id',    // SO→invoice AR join
            'delivery_id',       // delivery→invoice join
        ],
        'delivery_items' => [
            'inspection_id',     // delivery↔QC join
        ],
        'official_receipts' => [
            'invoice_id',        // OR→invoice (collection side already indexed)
        ],
        'supplier_invoices' => [
            'vendor_id',             // payables by vendor
            'purchase_order_id',     // payables by PO
        ],
        'work_order_outputs' => [
            'rework_ncr_id',     // rework joins (crew/rework productivity)
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
