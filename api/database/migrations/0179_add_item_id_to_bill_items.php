<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * H-7 — Persist item_id FK on bill_items.
 *
 * Bill lines historically had no link to the inventory Item — the 3-way match
 * service aligned bill lines to PO lines by INDEX POSITION, which silently
 * corrupts the variance check whenever a bill line is skipped or reordered.
 *
 * This migration:
 *   1. Adds a nullable `item_id` FK on bill_items, after expense_account_id.
 *   2. Backfills legacy rows by reading the matching PO line by index. That
 *      is the same broken alignment H-7 fixes going forward, but it is the
 *      best we can do for rows already in the table. New writes use the FK.
 *
 * Idempotent (matches the 0174/0177 defensive style); the backfill is
 * wrapped per-bill so a malformed legacy row does not abort the whole run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bill_items')) {
            return;
        }

        if (! Schema::hasColumn('bill_items', 'item_id')) {
            Schema::table('bill_items', function (Blueprint $t) {
                $t->foreignId('item_id')->nullable()->after('expense_account_id')
                    ->constrained('items')->nullOnDelete();
                $t->index('item_id');
            });
        }

        // Backfill: for bills with a purchase_order_id, copy item_id from the
        // matching PO line by index. Best-effort only; legacy alignment.
        try {
            $bills = DB::table('bills')->whereNotNull('purchase_order_id')->pluck('purchase_order_id', 'id');
            foreach ($bills as $billId => $poId) {
                try {
                    $billItemIds = DB::table('bill_items')
                        ->where('bill_id', $billId)
                        ->orderBy('id')
                        ->pluck('id')
                        ->all();
                    $poItemIds = DB::table('purchase_order_items')
                        ->where('purchase_order_id', $poId)
                        ->orderBy('id')
                        ->pluck('item_id')
                        ->values()
                        ->all();
                    foreach ($billItemIds as $idx => $biId) {
                        if (isset($poItemIds[$idx]) && $poItemIds[$idx] !== null) {
                            DB::table('bill_items')
                                ->where('id', $biId)
                                ->whereNull('item_id')
                                ->update(['item_id' => $poItemIds[$idx]]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('0179 backfill failed for bill', [
                        'bill_id' => $billId,
                        'po_id'   => $poId,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('0179 backfill bulk lookup failed', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bill_items') || ! Schema::hasColumn('bill_items', 'item_id')) {
            return;
        }
        Schema::table('bill_items', function (Blueprint $t) {
            try {
                $t->dropForeign(['item_id']);
            } catch (\Throwable $e) {
                // best-effort
            }
            try {
                $t->dropIndex(['item_id']);
            } catch (\Throwable $e) {
                // best-effort
            }
            $t->dropColumn('item_id');
        });
    }
};
