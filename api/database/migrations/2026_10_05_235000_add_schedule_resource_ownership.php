<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('production_schedules', 'reserves_capacity')) {
            return;
        }
        Schema::table('production_schedules', function (Blueprint $table) {
            $table->boolean('reserves_capacity')->default(true);
            $table->foreignId('rework_ncr_id')->nullable()->constrained('non_conformance_reports')->restrictOnDelete();
        });
        Schema::table('molds', function (Blueprint $table) {
            $table->foreignId('current_work_order_id')->nullable()->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('current_rework_ncr_id')->nullable()->constrained('non_conformance_reports')->restrictOnDelete();
        });
        Schema::table('machines', function (Blueprint $table) {
            $table->foreignId('current_rework_ncr_id')->nullable()->constrained('non_conformance_reports')->restrictOnDelete();
        });
        Schema::table('work_orders', fn (Blueprint $table) => $table->timestamp('production_released_at')->nullable());

        // Preserve execution history while ending verified terminal reservations.
        DB::statement("UPDATE production_schedules s SET reserves_capacity = false FROM work_orders w WHERE s.work_order_id = w.id AND w.status IN ('completed','closed','cancelled') AND NOT EXISTS (SELECT 1 FROM non_conformance_reports n WHERE n.rework_work_order_id = w.id AND n.rework_started_at IS NOT NULL AND n.rework_finished_at IS NULL)");
        $owners = DB::table('work_orders')->whereIn('status', ['in_progress', 'paused'])->whereNull('deleted_at')->whereNotNull('mold_id')->get(['id', 'mold_id']);
        foreach ($owners->groupBy('mold_id') as $moldId => $rows) {
            if ($rows->count() !== 1) {
                throw new RuntimeException('Conflicting installed mold owners require production review before migration. Mold '.$moldId);
            }
            DB::table('molds')->where('id', $moldId)->update(['current_work_order_id' => $rows->first()->id]);
        }
        foreach (DB::table('non_conformance_reports')->whereNotNull('rework_started_at')->whereNull('rework_finished_at')->whereNotNull('rework_machine_id')->get() as $ncr) {
            $mold = DB::table('molds')->where('id', $ncr->rework_mold_id)->first();
            if ($mold->current_work_order_id !== null) {
                throw new RuntimeException('Conflicting repair mold owner requires review. NCR '.$ncr->id);
            }
            DB::table('molds')->where('id', $ncr->rework_mold_id)->update(['current_work_order_id' => $ncr->rework_work_order_id, 'current_rework_ncr_id' => $ncr->id]);
            DB::table('machines')->where('id', $ncr->rework_machine_id)->update(['current_rework_ncr_id' => $ncr->id]);
        }
        foreach (['production_schedules_no_overlap' => 'machine_id', 'production_schedules_mold_no_overlap' => 'mold_id'] as $name => $column) {
            DB::statement('ALTER TABLE production_schedules DROP CONSTRAINT '.$name);
            DB::statement("ALTER TABLE production_schedules ADD CONSTRAINT {$name} EXCLUDE USING gist ({$column} WITH =, tsrange(scheduled_start, scheduled_end) WITH &&) WHERE (reserves_capacity AND status IN ('pending','confirmed','executed'))");
        }
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_production_schedule_immutable() RETURNS trigger AS $$
DECLARE wo_status text;
BEGIN
 SELECT status INTO wo_status FROM work_orders WHERE id = COALESCE(NEW.work_order_id, OLD.work_order_id);
 IF wo_status IN ('in_progress', 'paused') THEN
  IF TG_OP = 'UPDATE' AND OLD.reserves_capacity AND NOT NEW.reserves_capacity
   AND (to_jsonb(OLD) - 'reserves_capacity' - 'updated_at') = (to_jsonb(NEW) - 'reserves_capacity' - 'updated_at') THEN RETURN NEW; END IF;
  RAISE EXCEPTION 'Cannot mutate the schedule of a started work order (status: %)', wo_status;
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        // Schema and history are retained; rolling back legacy application code is unsafe
        // because it does not enforce exact ownership. Refuse instead of losing guards.
        throw new RuntimeException('Ownership migration cannot be rolled back while legacy production code ignores ownership; deploy compatible application code.');
    }
};
