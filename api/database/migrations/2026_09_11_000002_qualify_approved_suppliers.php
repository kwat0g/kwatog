<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Honest ASL + soft-delete-safe keys (2026-09-11).
 *
 * 1. `approved_suppliers` gains `qualification_status` (approved | provisional).
 *    Approving a PO no longer silently blesses a vendor for any item it shipped:
 *    it records a `provisional` link that purchasing can review. Supplier-listing
 *    review and manual ASL entry stay `approved`.
 *
 * 2. The original unique (item_id, vendor_id) index ignored soft-deletes: a
 *    deleted pair could never be re-added (SQLSTATE 23505). It is replaced by a
 *    partial unique index scoped to live rows.
 *
 * 3. "One preferred per item" moves from application-only to a partial unique
 *    index, closing the concurrent-write race that could leave two preferred
 *    vendors and make the `.first()` lookups non-deterministic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approved_suppliers', function (Blueprint $table) {
            $table->string('qualification_status', 20)->default('approved')->after('is_preferred');
        });

        Schema::table('approved_suppliers', function (Blueprint $table) {
            $table->dropUnique('approved_suppliers_item_vendor_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX approved_suppliers_item_vendor_active_unique '
            .'ON approved_suppliers (item_id, vendor_id) WHERE deleted_at IS NULL'
        );

        // Existing databases may already hold more than one preferred vendor for
        // an item — the old demo seeder set `is_preferred` at random, and the
        // rule was application-only until now. Demote all but the lowest-id live
        // preferred row per item so the partial unique index below can be built.
        // Portable across PostgreSQL and SQLite (no DISTINCT ON / NULLS LAST).
        DB::statement(
            'UPDATE approved_suppliers AS a SET is_preferred = false '
            .'WHERE a.is_preferred = true AND a.deleted_at IS NULL AND EXISTS ('
            .'  SELECT 1 FROM approved_suppliers AS b '
            .'  WHERE b.item_id = a.item_id AND b.is_preferred = true '
            .'    AND b.deleted_at IS NULL AND b.id < a.id'
            .')'
        );

        DB::statement(
            'CREATE UNIQUE INDEX approved_suppliers_item_preferred_active_unique '
            .'ON approved_suppliers (item_id) WHERE is_preferred AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS approved_suppliers_item_preferred_active_unique');
        DB::statement('DROP INDEX IF EXISTS approved_suppliers_item_vendor_active_unique');

        Schema::table('approved_suppliers', function (Blueprint $table) {
            $table->unique(['item_id', 'vendor_id'], 'approved_suppliers_item_vendor_unique');
            $table->dropColumn('qualification_status');
        });
    }
};
