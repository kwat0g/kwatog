<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (dashboard/DSS) — finance cycle-time indexes, Phase 0 B §4 gaps
 * 25-26, 39-41.
 *
 * `budgets` and `credit_note_lines` had ONLY a primary key, so the budget-vs-
 * actual and credit-note panels were full scans per filter; `budget_transfers`
 * had a status index but neither of its line endpoints, so transfer flow could
 * not be followed from either side. `purchase_requests.date` and
 * `purchase_orders.date` were missing while status/department were indexed, so
 * PR aging and PO cycle-time trends (the purchasing dashboard's day-one panels)
 * scanned the whole table.
 *
 * Timestamp-named (these tables are also touched by `2026_*` migrations). No
 * silent `hasColumn` guards — see 2026_10_11_090000.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => columns to index */
    private const INDEXES = [
        'budgets' => [
            'fiscal_year_id',        // THE budget filter
            'department_id',         // budget by department
            'status',                // draft/submitted/active boards
        ],
        'budget_transfers' => [
            'from_line_item_id',     // transfer flow, source side
            'to_line_item_id',       // transfer flow, destination side
        ],
        'credit_note_lines' => [
            'credit_note_id',        // line→header (table had only pkey)
            'account_id',            // CN by GL account
        ],
        'purchase_requests' => [
            'date',                  // PR aging / intake trend
        ],
        'purchase_orders' => [
            'date',                  // PO cycle time
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
