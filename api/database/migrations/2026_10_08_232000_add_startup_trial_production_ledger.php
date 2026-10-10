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
        Schema::table('inspections', function (Blueprint $table): void {
            $table->boolean('startup_trial_required')->default(false);
        });
        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->foreignId('trial_inspection_id')->nullable()->unique()->constrained('inspections')->restrictOnDelete();
            $table->unsignedInteger('trial_quantity')->nullable();
            $table->timestamp('trial_settled_at')->nullable();
        });
        DB::statement('ALTER TABLE work_order_outputs ADD CONSTRAINT work_order_output_trial_check CHECK ((trial_inspection_id IS NULL AND trial_quantity IS NULL AND trial_settled_at IS NULL) OR (trial_inspection_id IS NOT NULL AND trial_quantity > 0 AND trial_quantity IS NOT NULL AND ((trial_settled_at IS NULL AND good_count = 0 AND reject_count = 0 AND production_receipt_movement_id IS NULL) OR (trial_settled_at IS NOT NULL AND good_count + reject_count = trial_quantity))))');

        // Only untouched startup drafts can gain the new evidence requirement.
        DB::statement("UPDATE inspections i SET startup_trial_required = true FROM work_orders w WHERE i.entity_type = 'work_order' AND i.entity_id = w.id AND i.stage = 'in_process' AND i.wo_operation_id IS NULL AND i.work_order_output_id IS NULL AND i.status = 'draft' AND i.completed_at IS NULL AND i.reviewed_at IS NULL AND i.reviewed_by IS NULL AND i.proposed_result IS NULL AND i.recorded_result IS NULL AND i.inspected_quantity IS NULL AND i.result_version = 0 AND i.defect_count = 0 AND COALESCE(i.sample_defect_count, 0) = 0 AND i.replaces_inspection_id IS NULL AND i.inspection_spec_revision_id IS NOT NULL AND w.parent_wo_id IS NULL AND w.parent_ncr_id IS NULL AND w.rework_source_output_id IS NULL AND w.status IN ('in_progress', 'paused') AND w.actual_start IS NOT NULL AND w.quantity_produced = 0 AND w.quantity_good = 0 AND w.quantity_rejected = 0 AND NOT EXISTS (SELECT 1 FROM work_order_outputs o WHERE o.work_order_id = w.id) AND NOT EXISTS (SELECT 1 FROM wo_operations op WHERE op.work_order_id = w.id AND (op.qty_completed > 0 OR op.qty_scrapped > 0)) AND NOT EXISTS (SELECT 1 FROM inspection_result_authors a WHERE a.inspection_id = i.id) AND NOT EXISTS (SELECT 1 FROM inspection_measurements m WHERE m.inspection_id = i.id AND (m.measured_value IS NOT NULL OR m.is_pass IS NOT NULL OR m.item_result IS NOT NULL)) AND NOT EXISTS (SELECT 1 FROM non_conformance_reports n WHERE n.inspection_id = i.id) AND NOT EXISTS (SELECT 1 FROM inspections other WHERE other.entity_type = 'work_order' AND other.entity_id = w.id AND other.stage = 'in_process' AND other.wo_operation_id IS NULL AND other.id <> i.id)");
    }

    public function down(): void
    {
        if (DB::table('work_order_outputs')->whereNotNull('trial_inspection_id')->exists()) {
            throw new RuntimeException('Startup trial evidence exists. Preserve the additive schema during application rollback.');
        }
        DB::statement('ALTER TABLE work_order_outputs DROP CONSTRAINT work_order_output_trial_check');
        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->dropForeign(['trial_inspection_id']);
            $table->dropUnique(['trial_inspection_id']);
            $table->dropColumn(['trial_inspection_id', 'trial_quantity', 'trial_settled_at']);
        });
        Schema::table('inspections', fn (Blueprint $table) => $table->dropColumn('startup_trial_required'));
    }
};
