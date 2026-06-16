<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-012 — Inventory lot/batch traceability (IATF 16949).
 *
 * Adds the lot/expiry capture columns the receive→issue ledger needs to
 * trace a supplier lot from GRN all the way to material issue.
 *
 * Reconciliation with the earlier ADV3 work (migration 0150):
 *   - `grn_items` ALREADY has `material_lot_number` (string 50) + an index
 *     from 0150, so we DO NOT add a second lot column there — we reuse it.
 *     We only add the missing `expiry_date`.
 *   - `stock_movements` has NO lot columns yet, so we add both `lot_number`
 *     (string 50) + `expiry_date` (date) and index the lot for ledger lookups.
 *   - `stock_count_items` already carries an incidental `lot_number` (0160) —
 *     untouched here to avoid duplication.
 *
 * Every column is nullable so existing callers/tests keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── grn_items: lot column already exists (material_lot_number, 0150).
        //    Only add the missing expiry_date.
        if (! Schema::hasColumn('grn_items', 'expiry_date')) {
            Schema::table('grn_items', function (Blueprint $table) {
                $table->date('expiry_date')->nullable()->after('supplier_lot_reference');
            });
        }

        // ── stock_movements: no lot columns yet — add both + index the lot.
        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'lot_number')) {
                $table->string('lot_number', 50)->nullable()->after('reference_id');
            }
            if (! Schema::hasColumn('stock_movements', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('lot_number');
            }
        });

        // Separate statement so hasColumn checks above have committed schema.
        if (Schema::hasColumn('stock_movements', 'lot_number')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->index(['item_id', 'lot_number'], 'stock_movements_item_lot_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_item_lot_index');
            $table->dropColumn(['lot_number', 'expiry_date']);
        });

        if (Schema::hasColumn('grn_items', 'expiry_date')) {
            Schema::table('grn_items', function (Blueprint $table) {
                $table->dropColumn('expiry_date');
            });
        }
    }
};
