<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow an in-process or outgoing inspection slot to be reused after the
 * previous inspection on it was CANCELLED.
 *
 * The partial unique indexes treated every row as final, so a cancelled
 * in-process inspection permanently consumed (in_process, work_order, id):
 * TriggerInProcessQC's exists() check found it and skipped re-creation, and
 * create()'s own reuse check returned it — a WO whose QC was cancelled (wrong
 * spec version opened in error, duplicate keyed by mistake) could never
 * receive in-process QC again. Same shape for outgoing per output batch.
 *
 * Cancelled rows stay on the record for the audit trail; only the UNIQUE
 * claim is released. Draft/in_progress/awaiting_review rows keep holding
 * their slot — a part-inspected lot must be completed or failed, not
 * silently re-scaffolded. Postgres-only partial indexes, matching the
 * migrations that created the ones being replaced (SQLite is rejected there
 * too on the same driver-capability grounds).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'The cancelled-inspection slot reuse migration requires PostgreSQL (partial indexes); received '.DB::getDriverName().'.'
            );
        }

        DB::statement('DROP INDEX IF EXISTS inspections_non_outgoing_entity_unique');
        DB::statement('CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique
            ON inspections (stage, entity_type, entity_id)
            WHERE stage <> \'incoming\'
              AND stage <> \'outgoing\'
              AND status <> \'cancelled\'');

        DB::statement('DROP INDEX IF EXISTS inspections_outgoing_output_unique');
        DB::statement('CREATE UNIQUE INDEX inspections_outgoing_output_unique
            ON inspections (stage, work_order_output_id)
            WHERE stage = \'outgoing\'
              AND work_order_output_id IS NOT NULL
              AND status <> \'cancelled\'');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'The cancelled-inspection slot reuse migration requires PostgreSQL (partial indexes); received '.DB::getDriverName().'.'
            );
        }

        // Refuse a rollback that would collide with real cancelled rows —
        // restoring the stricter index with cancelled rows present would fail
        // mid-migrate and leave the schema half-changed.
        $cancelled = DB::table('inspections')->where('status', 'cancelled')->exists();
        if ($cancelled) {
            throw new RuntimeException(
                'Cannot roll back cancelled-inspection slot reuse: cancelled inspection rows exist. Preserve the audit trail and migrate explicitly.'
            );
        }

        DB::statement('DROP INDEX IF EXISTS inspections_non_outgoing_entity_unique');
        DB::statement('CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique
            ON inspections (stage, entity_type, entity_id)
            WHERE stage <> \'incoming\'
              AND stage <> \'outgoing\'');

        DB::statement('DROP INDEX IF EXISTS inspections_outgoing_output_unique');
        DB::statement('CREATE UNIQUE INDEX inspections_outgoing_output_unique
            ON inspections (stage, work_order_output_id)
            WHERE stage = \'outgoing\'
              AND work_order_output_id IS NOT NULL');
    }
};
