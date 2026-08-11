<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Incoming QC is line-scoped when a GRN has item quality plans, while
 * in-process/outgoing QC remains entity-scoped. The original global
 * inspections_stage_entity_unique index made a two-line GRN impossible to
 * inspect: the second line collided on (incoming, grn, grn_id).
 *
 * Keep the database as the concurrency authority with two partial indexes:
 * - non-incoming stages: one inspection per stage/entity;
 * - legacy incoming rows without grn_item_id: one inspection per GRN.
 *
 * The existing inspection_grn_line_stage_unique index continues to enforce
 * one incoming inspection per GRN line.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Laravel creates Schema::unique() as a PostgreSQL table
            // constraint, even though it is backed by an index.
            DB::statement(
                'ALTER TABLE inspections DROP CONSTRAINT IF EXISTS inspections_stage_entity_unique'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS inspections_stage_entity_unique');
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX inspections_non_incoming_entity_unique '
                .'ON inspections (stage, entity_type, entity_id) '
                ."WHERE stage <> 'incoming'"
            );
            DB::statement(
                'CREATE UNIQUE INDEX inspections_legacy_incoming_entity_unique '
                .'ON inspections (stage, entity_type, entity_id) '
                ."WHERE stage = 'incoming' AND grn_item_id IS NULL"
            );

            return;
        }

        // The application currently supports PostgreSQL and SQLite. Keep a
        // clear failure for an unsupported driver instead of silently
        // weakening the inspection idempotency invariant.
        throw new RuntimeException(
            "Inspection uniqueness migration requires a partial-index capable driver; received {$driver}."
        );
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS inspections_non_incoming_entity_unique');
            DB::statement('DROP INDEX IF EXISTS inspections_legacy_incoming_entity_unique');
            if ($driver === 'pgsql') {
                DB::statement(
                    'ALTER TABLE inspections ADD CONSTRAINT inspections_stage_entity_unique '
                    .'UNIQUE (stage, entity_type, entity_id)'
                );
            } else {
                DB::statement(
                    'CREATE UNIQUE INDEX inspections_stage_entity_unique '
                    .'ON inspections (stage, entity_type, entity_id)'
                );
            }
        }
    }
};
