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
        foreach (['inspection_spec_revisions', 'item_quality_plans', 'inspections'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('capture_method', 20)->default('measurement'));
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_capture_method_check CHECK (capture_method IN ('measurement', 'checklist'))");
        }
        foreach (['inspection_spec_items', 'inspection_measurements'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('acceptance_criterion', 500)->nullable();
                $table->string('parameter_type', 20)->nullable()->change();
            });
            // PostgreSQL CHECK permits NULL; existing non-null type/window checks remain.
        }
        Schema::table('inspections', function (Blueprint $table): void {
            $table->decimal('source_quantity', 15, 3)->nullable();
            $table->string('source_uom_code', 20)->nullable();
            $table->string('sampling_basis', 10)->nullable();
            $table->unsignedInteger('result_version')->default(0);
            $table->foreignId('replaces_inspection_id')->nullable()->unique()->constrained('inspections')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE inspections ADD CONSTRAINT inspections_checklist_source_check CHECK (capture_method <> 'checklist' OR (source_quantity > 0 AND source_quantity IS NOT NULL AND source_uom_code IS NOT NULL AND sampling_basis IN ('piece', 'lot') AND sampling_basis IS NOT NULL))");
        DB::statement('ALTER TABLE inspections ADD CONSTRAINT inspections_result_version_check CHECK (result_version >= 0)');
    }

    public function down(): void
    {
        if (DB::table('inspections')->whereNotNull('replaces_inspection_id')->orWhere('result_version', '>', 0)->exists()) {
            throw new RuntimeException('Result history exists; rollback would destroy evidence.');
        }
        foreach (['inspection_spec_revisions', 'item_quality_plans', 'inspections'] as $name) {
            if (DB::table($name)->where('capture_method', 'checklist')->exists()) {
                throw new RuntimeException('Checklist evidence exists. Pause new capture; preserve the additive schema.');
            }
        }
        foreach (['inspection_spec_items', 'inspection_measurements'] as $name) {
            if (DB::table($name)->whereNotNull('acceptance_criterion')->exists()
                || DB::table($name)->whereNull('parameter_type')->exists()) {
                throw new RuntimeException('Checklist criteria exist; rollback would destroy evidence.');
            }
        }
        DB::statement('ALTER TABLE inspections DROP CONSTRAINT inspections_checklist_source_check');
        DB::statement('ALTER TABLE inspections DROP CONSTRAINT inspections_result_version_check');
        Schema::table('inspections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaces_inspection_id');
            $table->dropColumn(['source_quantity', 'source_uom_code', 'sampling_basis', 'result_version']);
        });
        foreach (['inspection_spec_revisions', 'item_quality_plans', 'inspections'] as $name) {
            DB::statement("ALTER TABLE {$name} DROP CONSTRAINT {$name}_capture_method_check");
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('capture_method'));
        }
        foreach (['inspection_spec_items', 'inspection_measurements'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('acceptance_criterion');
                $table->string('parameter_type', 20)->nullable(false)->change();
            });
        }
    }
};
