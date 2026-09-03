<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M050 — capacity-scheduling invariants at the database layer.
 *
 *  1. production_schedules_no_overlap: a machine can never carry two active
 *     (pending/confirmed/executed) rows with overlapping windows. The
 *     scheduler and the manual reassign path already avoid overlaps; this
 *     constraint makes an overlap a hard database error instead of a silent
 *     double-booking. Superseded rows are exempt (they free their window).
 *  2. production_schedules_immutable_guard: the time window of a schedule
 *     whose work order is running (in_progress/paused) is an operational
 *     record — raw SQL cannot rewrite it. Eloquent writes are already
 *     silently cancelled by the ProductionSchedule model hook; this trigger
 *     raises so direct SQL (imports, migrations, ad-hoc scripts) is stopped
 *     loudly instead of corrupting the plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // machine_id WITH = in the GiST exclusion constraint needs btree_gist.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist;');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_production_schedule_immutable()
            RETURNS trigger AS $$
            DECLARE
                wo_status text;
            BEGIN
                SELECT status INTO wo_status
                  FROM work_orders
                 WHERE id = COALESCE(NEW.work_order_id, OLD.work_order_id);

                IF wo_status IN ('in_progress', 'paused') THEN
                    RAISE EXCEPTION 'Cannot mutate the schedule of a started work order (status: %)', wo_status;
                END IF;

                -- For a DELETE the returned row must be OLD; RETURN NEW on a
                -- delete silently aborts the row removal.
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::statement(
            'CREATE TRIGGER production_schedules_immutable_guard '
            . 'BEFORE UPDATE OR DELETE ON production_schedules '
            . 'FOR EACH ROW EXECUTE FUNCTION guard_production_schedule_immutable();'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE production_schedules
              ADD CONSTRAINT production_schedules_no_overlap
              EXCLUDE USING gist (
                machine_id WITH =,
                tsrange(scheduled_start, scheduled_end) WITH &&
              )
              WHERE (status IN ('pending', 'confirmed', 'executed'));
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE production_schedules DROP CONSTRAINT IF EXISTS production_schedules_no_overlap;');
        DB::statement('DROP TRIGGER IF EXISTS production_schedules_immutable_guard ON production_schedules;');
        DB::statement('DROP FUNCTION IF EXISTS guard_production_schedule_immutable();');
    }
};