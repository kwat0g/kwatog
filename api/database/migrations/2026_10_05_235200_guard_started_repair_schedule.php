<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_production_schedule_immutable() RETURNS trigger AS $$
DECLARE wo_status text; repair_started boolean;
BEGIN
 SELECT status INTO wo_status FROM work_orders WHERE id = OLD.work_order_id;
 IF EXISTS (SELECT 1 FROM work_orders WHERE id = NEW.work_order_id AND status IN ('in_progress','paused')) THEN wo_status := 'in_progress'; END IF;
 SELECT EXISTS (SELECT 1 FROM non_conformance_reports WHERE id IN (NEW.rework_ncr_id, OLD.rework_ncr_id) AND rework_started_at IS NOT NULL) INTO repair_started;
 IF wo_status IN ('in_progress', 'paused') OR COALESCE(repair_started, false) THEN
  IF TG_OP = 'UPDATE' AND OLD.reserves_capacity AND NOT NEW.reserves_capacity
   AND (to_jsonb(OLD) - 'reserves_capacity' - 'updated_at') = (to_jsonb(NEW) - 'reserves_capacity' - 'updated_at') THEN RETURN NEW; END IF;
  RAISE EXCEPTION 'Cannot mutate started production or repair schedule timing';
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        // Keep repair execution history protected when application code rolls back.
    }
};
