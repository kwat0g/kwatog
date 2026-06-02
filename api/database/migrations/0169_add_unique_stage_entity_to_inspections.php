<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.7 — Outgoing QC idempotency guard.
 *
 * Adds a composite unique index on (stage, entity_type, entity_id) so the
 * database enforces the invariant: exactly one inspection of a given stage
 * per entity. This prevents duplicate outgoing inspections when two queue
 * workers both pass the check-then-insert guard in TriggerOutgoingQC.
 *
 * Invariant analysis:
 *   - A WO legitimately has in_process (stage differs) AND outgoing (different
 *     stage value) — the 3-column composite covers both safely.
 *   - Rows with NULL entity_type or entity_id are NOT constrained (NULL ≠ NULL
 *     in SQL's unique semantics — both PostgreSQL and SQLite follow this).
 *   - Partial index (WHERE stage='outgoing') was considered but rejected because
 *     SQLite does not support partial indexes; this composite is equally correct.
 *
 * Safe on fresh schema (no duplicate data in seeds) and on existing DBs because
 * seeds create inspections with NULL entity columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->unique(
                ['stage', 'entity_type', 'entity_id'],
                'inspections_stage_entity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropUnique('inspections_stage_entity_unique');
        });
    }
};
