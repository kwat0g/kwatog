<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M026 — keep posted journal aggregates and their lines append-only.
 *
 * Eloquent guards protect the normal service path; these database guards close
 * the raw-query escape hatch as well. SQLite receives equivalent triggers for
 * the feature and test environments; PostgreSQL receives trigger functions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_entries') && ! Schema::hasColumn('journal_entries', 'reversal_reason')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->text('reversal_reason')->nullable()->after('description');
            });
        }

        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('journal_entry_lines')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS journal_entry_lines_immutable ON journal_entry_lines;
DROP FUNCTION IF EXISTS prevent_posted_journal_line_mutation();

CREATE FUNCTION prevent_posted_journal_line_mutation()
RETURNS TRIGGER AS $$
DECLARE
    old_status text;
    new_status text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        SELECT status INTO old_status
        FROM journal_entries
        WHERE id = OLD.journal_entry_id;
    END IF;

    IF TG_OP <> 'DELETE' THEN
        SELECT status INTO new_status
        FROM journal_entries
        WHERE id = NEW.journal_entry_id;
    END IF;

    IF old_status IN ('posted', 'reversed') OR new_status IN ('posted', 'reversed') THEN
        RAISE EXCEPTION 'Posted journal entry lines are immutable.';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER journal_entry_lines_immutable
BEFORE INSERT OR UPDATE OR DELETE ON journal_entry_lines
FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_line_mutation();

DROP TRIGGER IF EXISTS journal_entries_immutable ON journal_entries;
DROP TRIGGER IF EXISTS journal_entries_delete_guard ON journal_entries;
DROP FUNCTION IF EXISTS prevent_posted_journal_mutation();

CREATE FUNCTION prevent_posted_journal_mutation()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status IN ('posted', 'reversed') THEN
            RAISE EXCEPTION 'Posted journal entries are immutable.';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status = 'posted' THEN
        IF NEW.entry_number IS DISTINCT FROM OLD.entry_number
            OR NEW.date IS DISTINCT FROM OLD.date
            OR NEW.description IS DISTINCT FROM OLD.description
            OR NEW.reversal_reason IS DISTINCT FROM OLD.reversal_reason
            OR NEW.reference_type IS DISTINCT FROM OLD.reference_type
            OR NEW.reference_id IS DISTINCT FROM OLD.reference_id
            OR NEW.total_debit IS DISTINCT FROM OLD.total_debit
            OR NEW.total_credit IS DISTINCT FROM OLD.total_credit
            OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
            OR NEW.posted_at IS DISTINCT FROM OLD.posted_at
            OR NEW.posted_by IS DISTINCT FROM OLD.posted_by
            OR NEW.created_by IS DISTINCT FROM OLD.created_by
            OR NEW.status NOT IN ('posted', 'reversed')
            OR (NEW.status = 'posted' AND NEW.reversed_by_entry_id IS DISTINCT FROM OLD.reversed_by_entry_id)
            OR (NEW.status = 'reversed' AND (
                NEW.reversed_by_entry_id IS NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM journal_entries reversal
                    WHERE reversal.id = NEW.reversed_by_entry_id
                      AND reversal.id <> OLD.id
                      AND reversal.status = 'posted'
                      AND reversal.reference_type = 'journal_entry_reversal'
                      AND reversal.reference_id = OLD.id
                      AND reversal.deleted_at IS NULL
                )
            ))
        THEN
            RAISE EXCEPTION 'Posted journal entries are immutable.';
        END IF;
    ELSIF OLD.status = 'reversed' THEN
        IF NEW.entry_number IS DISTINCT FROM OLD.entry_number
            OR NEW.date IS DISTINCT FROM OLD.date
            OR NEW.description IS DISTINCT FROM OLD.description
            OR NEW.reversal_reason IS DISTINCT FROM OLD.reversal_reason
            OR NEW.reference_type IS DISTINCT FROM OLD.reference_type
            OR NEW.reference_id IS DISTINCT FROM OLD.reference_id
            OR NEW.total_debit IS DISTINCT FROM OLD.total_debit
            OR NEW.total_credit IS DISTINCT FROM OLD.total_credit
            OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
            OR NEW.status IS DISTINCT FROM OLD.status
            OR NEW.reversed_by_entry_id IS DISTINCT FROM OLD.reversed_by_entry_id
            OR NEW.posted_at IS DISTINCT FROM OLD.posted_at
            OR NEW.posted_by IS DISTINCT FROM OLD.posted_by
            OR NEW.created_by IS DISTINCT FROM OLD.created_by
        THEN
            RAISE EXCEPTION 'Reversed journal entries are immutable.';
        END IF;
    ELSIF OLD.status = 'draft' THEN
        IF NEW.status NOT IN ('draft', 'posted') THEN
            RAISE EXCEPTION 'Invalid journal entry lifecycle transition.';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER journal_entries_immutable
BEFORE UPDATE ON journal_entries
FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();

CREATE TRIGGER journal_entries_delete_guard
BEFORE DELETE ON journal_entries
FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();
SQL);
        } elseif ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS journal_entry_lines_immutable_insert
BEFORE INSERT ON journal_entry_lines
WHEN EXISTS (SELECT 1 FROM journal_entries WHERE id = NEW.journal_entry_id AND status IN ('posted', 'reversed'))
BEGIN SELECT RAISE(ABORT, 'Posted journal entry lines are immutable.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS journal_entry_lines_immutable_update
BEFORE UPDATE ON journal_entry_lines
WHEN EXISTS (SELECT 1 FROM journal_entries WHERE id = OLD.journal_entry_id AND status IN ('posted', 'reversed'))
   OR EXISTS (SELECT 1 FROM journal_entries WHERE id = NEW.journal_entry_id AND status IN ('posted', 'reversed'))
BEGIN SELECT RAISE(ABORT, 'Posted journal entry lines are immutable.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS journal_entry_lines_immutable_delete
BEFORE DELETE ON journal_entry_lines
WHEN EXISTS (SELECT 1 FROM journal_entries WHERE id = OLD.journal_entry_id AND status IN ('posted', 'reversed'))
BEGIN SELECT RAISE(ABORT, 'Posted journal entry lines are immutable.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS journal_entries_immutable_update
BEFORE UPDATE ON journal_entries
WHEN OLD.status = 'posted' AND (
    NEW.entry_number IS NOT OLD.entry_number
    OR NEW.date IS NOT OLD.date
    OR NEW.description IS NOT OLD.description
    OR NEW.reversal_reason IS NOT OLD.reversal_reason
    OR NEW.reference_type IS NOT OLD.reference_type
    OR NEW.reference_id IS NOT OLD.reference_id
    OR NEW.total_debit IS NOT OLD.total_debit
    OR NEW.total_credit IS NOT OLD.total_credit
    OR NEW.deleted_at IS NOT OLD.deleted_at
    OR NEW.posted_at IS NOT OLD.posted_at
    OR NEW.posted_by IS NOT OLD.posted_by
    OR NEW.created_by IS NOT OLD.created_by
    OR NEW.status NOT IN ('posted', 'reversed')
    OR (NEW.status = 'posted' AND NEW.reversed_by_entry_id IS NOT OLD.reversed_by_entry_id)
    OR (NEW.status = 'reversed' AND (
        NEW.reversed_by_entry_id IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM journal_entries reversal
            WHERE reversal.id = NEW.reversed_by_entry_id
              AND reversal.id IS NOT OLD.id
              AND reversal.status = 'posted'
              AND reversal.reference_type = 'journal_entry_reversal'
              AND reversal.reference_id = OLD.id
              AND reversal.deleted_at IS NULL
        )
    ))
)
BEGIN SELECT RAISE(ABORT, 'Posted journal entries are immutable.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS journal_entries_reversed_immutable_update
BEFORE UPDATE ON journal_entries
WHEN OLD.status = 'reversed' AND (
    NEW.entry_number IS NOT OLD.entry_number
    OR NEW.date IS NOT OLD.date
    OR NEW.description IS NOT OLD.description
    OR NEW.reversal_reason IS NOT OLD.reversal_reason
    OR NEW.reference_type IS NOT OLD.reference_type
    OR NEW.reference_id IS NOT OLD.reference_id
    OR NEW.total_debit IS NOT OLD.total_debit
    OR NEW.total_credit IS NOT OLD.total_credit
    OR NEW.deleted_at IS NOT OLD.deleted_at
    OR NEW.status IS NOT OLD.status
    OR NEW.reversed_by_entry_id IS NOT OLD.reversed_by_entry_id
    OR NEW.posted_at IS NOT OLD.posted_at
    OR NEW.posted_by IS NOT OLD.posted_by
    OR NEW.created_by IS NOT OLD.created_by
)
BEGIN SELECT RAISE(ABORT, 'Reversed journal entries are immutable.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS journal_entries_immutable_delete
BEFORE DELETE ON journal_entries
WHEN OLD.status IN ('posted', 'reversed')
BEGIN SELECT RAISE(ABORT, 'Posted journal entries are immutable.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS journal_entries_draft_transition_guard
BEFORE UPDATE ON journal_entries
WHEN OLD.status = 'draft' AND NEW.status NOT IN ('draft', 'posted')
BEGIN SELECT RAISE(ABORT, 'Invalid journal entry lifecycle transition.'); END
SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS journal_entry_lines_immutable ON journal_entry_lines;
DROP FUNCTION IF EXISTS prevent_posted_journal_line_mutation();
DROP TRIGGER IF EXISTS journal_entries_immutable ON journal_entries;
DROP TRIGGER IF EXISTS journal_entries_delete_guard ON journal_entries;
DROP FUNCTION IF EXISTS prevent_posted_journal_mutation();
SQL);
        } elseif ($driver === 'sqlite') {
            foreach ([
                'journal_entry_lines_immutable_insert',
                'journal_entry_lines_immutable_update',
                'journal_entry_lines_immutable_delete',
                'journal_entries_immutable_update',
                'journal_entries_reversed_immutable_update',
                'journal_entries_draft_transition_guard',
                'journal_entries_immutable_delete',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS "'.$trigger.'"');
            }
        }

        if (Schema::hasTable('journal_entries') && Schema::hasColumn('journal_entries', 'reversal_reason')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->dropColumn('reversal_reason');
            });
        }
    }
};
