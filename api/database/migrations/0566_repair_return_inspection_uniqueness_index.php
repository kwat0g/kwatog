<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair `inspections_non_outgoing_entity_unique` — 0565 rebuilt it with the
 * pre-0506 shape and silently re-broke multi-product return inspections.
 *
 * History of this index:
 *   0468  (stage, entity_type, entity_id)                 — one inspection per return entity
 *   0506  (stage, entity_type, entity_id, COALESCE(product_id, 0))
 *         — one inspection per PRODUCT per return entity, so a multi-line
 *           RMA stages one customer_return inspection per product
 *           (ReturnRequestService groups RMA items by product_id and
 *           intentionally creates one row each; the behavior is pinned by
 *           ReturnInspectionHandoffTest::test_multi_product_return_stages_one_inspection_per_product)
 *   0565  rebuilt the index to add `AND status <> 'cancelled'` (cancelled
 *         inspection slots become reusable) but copied the COLUMN LIST from
 *         0468, dropping the product column. The second product of any
 *         multi-product RMA then died with SQLSTATE 23505 on
 *         (stage, entity_type, entity_id) — observed in the E2E audit suite
 *         (ReturnInspectionHandoffTest, 2026-09-30).
 *
 * This migration restores the conjunction of both repairs: per-product keys
 * AND cancelled rows release their slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'The return-inspection uniqueness repair requires PostgreSQL (partial indexes); received '.DB::getDriverName().'.'
            );
        }

        DB::statement('DROP INDEX IF EXISTS inspections_non_outgoing_entity_unique');
        DB::statement(
            'CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique '
            .'ON inspections (stage, entity_type, entity_id, COALESCE(product_id, 0)) '
            ."WHERE stage <> 'incoming' AND stage <> 'outgoing' AND status <> 'cancelled'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'The return-inspection uniqueness repair requires PostgreSQL (partial indexes); received '.DB::getDriverName().'.'
            );
        }

        // Return to exactly 0565's shape (its down() precondition applies:
        // refuse when cancelled rows exist, because the stricter index would
        // fail mid-migrate and leave the schema half-changed).
        $cancelled = DB::table('inspections')->where('status', 'cancelled')->exists();
        if ($cancelled) {
            throw new RuntimeException(
                'Cannot roll back to the 0565 index shape: cancelled inspection rows exist. Preserve the audit trail and migrate explicitly.'
            );
        }

        DB::statement('DROP INDEX IF EXISTS inspections_non_outgoing_entity_unique');
        DB::statement('CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique
            ON inspections (stage, entity_type, entity_id)
            WHERE stage <> \'incoming\'
              AND stage <> \'outgoing\'
              AND status <> \'cancelled\'');
    }
};
