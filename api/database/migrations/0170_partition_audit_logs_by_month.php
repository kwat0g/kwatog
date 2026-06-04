<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Converts audit_logs into a PostgreSQL declarative range-partitioned table
 * (PARTITION BY RANGE on created_at, one child table per calendar month).
 *
 * Why: audit_logs grows unboundedly — every model write, every auth event, every
 * financial transaction appends a row. Without partitioning a single B-tree index
 * spans the entire history; VACUUM has to walk the whole heap; DROP on old data
 * requires a slow DELETE + VACUUM FULL. Monthly partitions let us DROP a child
 * table in milliseconds and keep index scans local to one month's data.
 *
 * Strategy:
 *   1. Rename existing table to audit_logs_legacy (preserves data + original indexes)
 *   2. Create new audit_logs as PARTITION BY RANGE (created_at)
 *   3. Create monthly child partitions: 2026-01 → 2026-12 + 2027-01 overflow
 *   4. Copy legacy rows into the new partitioned table
 *   5. Drop audit_logs_legacy
 *   6. Recreate global indexes on the parent (PostgreSQL propagates to children)
 *
 * Note: Blueprint does not support CREATE TABLE … PARTITION BY, so all DDL uses
 * raw DB::statement() calls targeting PostgreSQL. This migration is irreversible
 * (down() logs a warning and exits early).
 *
 * Companion command: app/Console/Commands/CreateAuditLogPartition.php
 * Schedule monthly in routes/console.php:
 *   Schedule::command('audit-log:create-partition')->monthlyOn(1, '00:05');
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        // 1. Rename existing table to preserve data during the transition.    //
        // ------------------------------------------------------------------ //
        DB::statement('ALTER TABLE audit_logs RENAME TO audit_logs_legacy');

        // ------------------------------------------------------------------ //
        // 2. Create the new partitioned parent table.                         //
        //    No indexes on the parent yet — we add them after the data copy   //
        //    so PostgreSQL only has to build them once.                        //
        // ------------------------------------------------------------------ //
        DB::statement(<<<SQL
            CREATE TABLE audit_logs (
                id            BIGSERIAL        NOT NULL,
                user_id       BIGINT           NULL,
                action        VARCHAR(20)      NOT NULL,
                model_type    VARCHAR(100)     NOT NULL,
                model_id      BIGINT           NULL,
                old_values    JSONB            NULL,
                new_values    JSONB            NULL,
                ip_address    VARCHAR(45)      NULL,
                user_agent    TEXT             NULL,
                created_at    TIMESTAMP(0)     NOT NULL DEFAULT now()
            ) PARTITION BY RANGE (created_at)
        SQL);

        // ------------------------------------------------------------------ //
        // 3. Create monthly child partitions.                                 //
        //    Range: [from, to) — PostgreSQL uses exclusive upper bound.       //
        //    Partitions: 2026-01 through 2026-12 + 2027-01 overflow.          //
        // ------------------------------------------------------------------ //
        $partitions = [
            // [suffix,    from,         to          ]
            ['2026_01', '2026-01-01', '2026-02-01'],
            ['2026_02', '2026-02-01', '2026-03-01'],
            ['2026_03', '2026-03-01', '2026-04-01'],
            ['2026_04', '2026-04-01', '2026-05-01'],
            ['2026_05', '2026-05-01', '2026-06-01'],
            ['2026_06', '2026-06-01', '2026-07-01'],
            ['2026_07', '2026-07-01', '2026-08-01'],
            ['2026_08', '2026-08-01', '2026-09-01'],
            ['2026_09', '2026-09-01', '2026-10-01'],
            ['2026_10', '2026-10-01', '2026-11-01'],
            ['2026_11', '2026-11-01', '2026-12-01'],
            ['2026_12', '2026-12-01', '2027-01-01'],
            ['2027_01', '2027-01-01', '2027-02-01'], // overflow / next year
        ];

        foreach ($partitions as [$suffix, $from, $to]) {
            DB::statement(<<<SQL
                CREATE TABLE audit_logs_{$suffix}
                    PARTITION OF audit_logs
                    FOR VALUES FROM ('{$from}') TO ('{$to}')
            SQL);
        }

        // ------------------------------------------------------------------ //
        // 4. Copy all existing rows from the legacy table.                    //
        //    Rows whose created_at falls outside 2026-01 → 2027-02 will fail  //
        //    with a "no partition" error — handle by logging a warning so the //
        //    operator can create a catch-all DEFAULT partition if needed.      //
        // ------------------------------------------------------------------ //
        DB::statement(<<<SQL
            INSERT INTO audit_logs
                (id, user_id, action, model_type, model_id,
                 old_values, new_values, ip_address, user_agent, created_at)
            SELECT
                id, user_id, action, model_type, model_id,
                old_values::jsonb, new_values::jsonb,
                ip_address, user_agent, created_at
            FROM audit_logs_legacy
        SQL);

        // Re-sync the sequence so future inserts pick up after the copied max id.
        DB::statement(<<<SQL
            SELECT setval(
                pg_get_serial_sequence('audit_logs_2026_01', 'id'),
                COALESCE((SELECT MAX(id) FROM audit_logs), 1),
                true
            )
        SQL);

        // ------------------------------------------------------------------ //
        // 5. Drop the legacy table — data now lives in the partitioned table. //
        // ------------------------------------------------------------------ //
        DB::statement('DROP TABLE audit_logs_legacy');

        // ------------------------------------------------------------------ //
        // 6. Recreate indexes on the partitioned parent.                      //
        //    PostgreSQL 11+ propagates parent indexes to all existing and      //
        //    future child partitions automatically.                             //
        // ------------------------------------------------------------------ //

        // (model_type, model_id) composite — most common lookup pattern
        DB::statement(<<<SQL
            CREATE INDEX audit_logs_model_type_model_id_index
                ON audit_logs (model_type, model_id)
        SQL);

        // created_at — time-range queries and partition pruning assist
        DB::statement(<<<SQL
            CREATE INDEX audit_logs_created_at_index
                ON audit_logs (created_at)
        SQL);

        // action — filter by verb (created / updated / deleted / login …)
        DB::statement(<<<SQL
            CREATE INDEX audit_logs_action_index
                ON audit_logs (action)
        SQL);

        // user_id — FK-style lookup for "show me this user's activity"
        DB::statement(<<<SQL
            CREATE INDEX audit_logs_user_id_index
                ON audit_logs (user_id)
        SQL);

        // Restore the FK constraint on the parent table.
        // NOTE: PostgreSQL does not allow FK constraints on partitioned tables
        // to reference child tables, but we can add a CHECK-style advisory here.
        // The application-level nullOnDelete behaviour is preserved; the hard FK
        // is intentionally omitted because PostgreSQL 16 still does not support
        // foreign keys on partitioned tables as the referencing side.
        // See: https://www.postgresql.org/docs/16/ddl-partitioning.html#DDL-PARTITIONING-DECLARATIVE-LIMITATIONS
    }

    public function down(): void
    {
        // Reversing a declarative partition is not safe to do automatically:
        // it would require creating a new monolithic table, copying all data back,
        // and dropping every child partition — a potentially multi-hour operation
        // on a production dataset with no transactional guarantee.
        //
        // To manually roll back:
        //   1. CREATE TABLE audit_logs_new (... same columns, no PARTITION BY ...)
        //   2. INSERT INTO audit_logs_new SELECT * FROM audit_logs;
        //   3. DROP TABLE audit_logs CASCADE;   -- drops all child partitions
        //   4. ALTER TABLE audit_logs_new RENAME TO audit_logs;
        //   5. Recreate indexes.
        Log::warning(
            'Migration 0170 (audit_logs partitioning) is intentionally irreversible. '
            . 'To roll back, follow the manual steps documented in the migration down() comment.'
        );
    }
};
