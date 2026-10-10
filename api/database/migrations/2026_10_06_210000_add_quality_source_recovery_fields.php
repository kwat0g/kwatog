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
        Schema::table('non_conformance_reports', function (Blueprint $table): void {
            $table->boolean('is_late_incoming')->default(false);
            $table->decimal('late_hold_quantity', 18, 3)->nullable();
        });
        Schema::table('inspections', fn (Blueprint $table) => $table->dropUnique('inspection_grn_line_stage_unique'));
        DB::statement("CREATE UNIQUE INDEX inspection_grn_line_stage_unique ON inspections (grn_item_id, stage) WHERE grn_item_id IS NOT NULL AND status <> 'cancelled'");
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->text('short_completion_reason')->nullable();
        });
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->foreignId('split_parent_item_id')->nullable()->constrained('return_request_items');
        });
        Schema::table('inspections', function (Blueprint $table): void {
            $table->foreignId('return_request_item_id')->nullable()->constrained('return_request_items');
        });
        DB::statement('DROP INDEX inspections_non_outgoing_entity_unique');
        DB::statement("CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique ON inspections (stage, entity_type, entity_id, COALESCE(product_id, 0), COALESCE(wo_operation_id, 0)) WHERE stage <> 'incoming' AND stage <> 'outgoing' AND status <> 'cancelled' AND return_request_item_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX inspection_return_line_stage_unique ON inspections (return_request_item_id, stage) WHERE return_request_item_id IS NOT NULL AND status <> 'cancelled'");
    }

    public function down(): void
    {
        if (DB::table('inspections')->whereNotNull('grn_item_id')->select('grn_item_id', 'stage')->groupBy('grn_item_id', 'stage')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cancelled inspection history prevents restoring the former receipt uniqueness constraint.');
        }
        DB::statement('DROP INDEX inspection_grn_line_stage_unique');
        Schema::table('inspections', fn (Blueprint $table) => $table->unique(['grn_item_id', 'stage'], 'inspection_grn_line_stage_unique'));
        Schema::table('non_conformance_reports', function (Blueprint $table): void {
            $table->dropColumn(['is_late_incoming', 'late_hold_quantity']);
        });
        if (DB::table('inspections')->where('status', '<>', 'cancelled')->whereNotIn('stage', ['incoming', 'outgoing'])->select('stage', 'entity_type', 'entity_id')->selectRaw('COALESCE(product_id, 0) AS product_key, COALESCE(wo_operation_id, 0) AS operation_key')->groupBy('stage', 'entity_type', 'entity_id')->groupByRaw('COALESCE(product_id, 0), COALESCE(wo_operation_id, 0)')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Item-specific return inspection history prevents restoring the former entity uniqueness constraint.');
        }
        DB::statement('DROP INDEX inspections_non_outgoing_entity_unique');
        DB::statement("CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique ON inspections (stage, entity_type, entity_id, COALESCE(product_id, 0), COALESCE(wo_operation_id, 0)) WHERE stage <> 'incoming' AND stage <> 'outgoing' AND status <> 'cancelled'");
        Schema::table('inspections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('return_request_item_id');
        });
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('split_parent_item_id');
        });
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropColumn('short_completion_reason');
        });
    }
};
