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
        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->unsignedInteger('held_quantity')->default(0);
            $table->timestamp('production_started_at')->nullable();
            $table->timestamp('production_finished_at')->nullable();
            $table->timestamp('held_settled_at')->nullable();
        });
        DB::statement('ALTER TABLE work_order_outputs ADD CONSTRAINT historical_output_evidence_check CHECK (held_quantity >= 0 AND (held_quantity = 0 OR (good_count = 0 AND reject_count = 0 AND trial_inspection_id IS NULL AND production_receipt_movement_id IS NULL)) AND ((production_started_at IS NULL AND production_finished_at IS NULL) OR (production_started_at IS NOT NULL AND production_finished_at > production_started_at)))');
        DB::statement('DROP INDEX inspections_non_outgoing_entity_unique');
        DB::statement("CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique ON inspections (stage, entity_type, entity_id, COALESCE(product_id, 0), COALESCE(wo_operation_id, 0), COALESCE(work_order_output_id, 0)) WHERE stage <> 'incoming' AND stage <> 'outgoing' AND status <> 'cancelled' AND return_request_item_id IS NULL");
    }

    public function down(): void
    {
        if (DB::table('work_order_outputs')->whereNotNull('production_started_at')->orWhere('held_quantity', '>', 0)->exists()) {
            throw new RuntimeException('Historical production evidence exists. Preserve the additive schema during application rollback.');
        }
        DB::statement('DROP INDEX inspections_non_outgoing_entity_unique');
        DB::statement("CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique ON inspections (stage, entity_type, entity_id, COALESCE(product_id, 0), COALESCE(wo_operation_id, 0)) WHERE stage <> 'incoming' AND stage <> 'outgoing' AND status <> 'cancelled' AND return_request_item_id IS NULL");
        DB::statement('ALTER TABLE work_order_outputs DROP CONSTRAINT historical_output_evidence_check');
        Schema::table('work_order_outputs', fn (Blueprint $table) => $table->dropColumn(['held_quantity', 'production_started_at', 'production_finished_at', 'held_settled_at']));
    }
};
