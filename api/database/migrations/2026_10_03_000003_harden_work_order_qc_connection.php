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
            $table->foreignId('wo_operation_id')->nullable()->constrained('wo_operations');
        });
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreignId('rework_source_output_id')->nullable()->constrained('work_order_outputs');
        });
        Schema::table('non_conformance_reports', function (Blueprint $table): void {
            $table->unsignedInteger('original_accepted_quantity')->nullable();
            $table->unsignedInteger('confirmed_rework_quantity')->nullable();
            $table->dropUnique('ncr_inspection_unique');
        });
        DB::statement("CREATE UNIQUE INDEX ncr_inspection_unique ON non_conformance_reports (inspection_id) WHERE status NOT IN ('closed','cancelled')");
        DB::statement('DROP INDEX IF EXISTS inspections_non_outgoing_entity_unique');
        DB::statement("CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique ON inspections (stage, entity_type, entity_id, COALESCE(product_id, 0), COALESCE(wo_operation_id, 0)) WHERE stage NOT IN ('incoming','outgoing') AND status <> 'cancelled'");
    }

    public function down(): void
    {
        if (DB::table('inspections')->whereNotNull('wo_operation_id')->exists()
            || DB::table('work_orders')->whereNotNull('rework_source_output_id')->exists()
            || DB::table('non_conformance_reports')->whereNotNull('inspection_id')->groupBy('inspection_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot remove QC recovery lineage while operation inspections, repair/recovery evidence or repeated incidents exist. Preserve the audit trail.');
        }
        DB::statement('DROP INDEX IF EXISTS ncr_inspection_unique');
        DB::statement('CREATE UNIQUE INDEX ncr_inspection_unique ON non_conformance_reports (inspection_id)');
        DB::statement('DROP INDEX IF EXISTS inspections_non_outgoing_entity_unique');
        DB::statement("CREATE UNIQUE INDEX inspections_non_outgoing_entity_unique ON inspections (stage, entity_type, entity_id, COALESCE(product_id, 0)) WHERE stage NOT IN ('incoming','outgoing') AND status <> 'cancelled'");
        Schema::table('non_conformance_reports', fn (Blueprint $table) => $table->dropColumn(['original_accepted_quantity', 'confirmed_rework_quantity']));
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('rework_source_output_id'));
        Schema::table('inspections', fn (Blueprint $table) => $table->dropConstrainedForeignId('wo_operation_id'));
    }
};
