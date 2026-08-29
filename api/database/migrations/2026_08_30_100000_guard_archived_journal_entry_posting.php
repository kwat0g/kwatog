<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M026 — an archived journal entry cannot be posted.
 *
 * `2026_08_25_100000_harden_journal_immutability` made Posted and Reversed rows
 * immutable including their `deleted_at`, but its draft branch only validated
 * the status transition:
 *
 *     ELSIF OLD.status = 'draft' THEN
 *         IF NEW.status NOT IN ('draft', 'posted') THEN ... END IF;
 *
 * so a raw writer could set `deleted_at` on a draft (legitimate — that is the
 * archive operation) and then flip its status to `posted`. Measured on
 * PostgreSQL: both statements were accepted, producing a POSTED row with
 * `deleted_at` set and its lines intact. Eloquent's soft-delete scope hides that
 * row from the journal list, while every statement aggregate joins
 * `journal_entry_lines` on `je.status = 'posted'` with no `deleted_at` filter and
 * counts it. The same two rows then answered the same question two ways —
 * ₱111.00 through `BudgetConsumptionService`'s filter versus ₱999.00 through
 * `TrialBalanceService`'s.
 *
 * This adds the one missing condition. It deliberately does NOT forbid trashing
 * a draft: archiving a draft is a supported operation with a Restore action
 * behind it. What it forbids is the promotion of an already-archived row, which
 * is the only way the hidden-but-counted state was reachable.
 *
 * Timestamp-named rather than `0479_`, because the migrator sorts by full
 * filename and '0' < '2': a `0NNN_` file runs before every `2026_*` one, so it
 * would try to replace a trigger function that does not exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            // CREATE OR REPLACE keeps both existing triggers bound to the
            // function, so neither has to be dropped and recreated.
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_posted_journal_mutation()
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

        -- The added guard. A hidden POSTED row is counted by every statement
        -- aggregate and shown by none of the journal screens.
        IF NEW.status = 'posted' AND NEW.deleted_at IS NOT NULL THEN
            RAISE EXCEPTION 'An archived journal entry cannot be posted. Restore it first.';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        } elseif ($driver === 'sqlite') {
            // SQLite cannot replace a trigger body, so the draft guard is added
            // as its own trigger alongside the existing transition guard.
            DB::statement('DROP TRIGGER IF EXISTS "journal_entries_archived_post_guard"');
            DB::statement(<<<'SQL'
CREATE TRIGGER journal_entries_archived_post_guard
BEFORE UPDATE ON journal_entries
WHEN OLD.status = 'draft' AND NEW.status = 'posted' AND NEW.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'An archived journal entry cannot be posted. Restore it first.'); END
SQL);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            // Restore the pre-guard body from 2026_08_25_100000 so `down` is a
            // true inverse rather than a drop that would leave the posted and
            // reversed branches unenforced.
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_posted_journal_mutation()
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
SQL);
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS "journal_entries_archived_post_guard"');
        }
    }
};
