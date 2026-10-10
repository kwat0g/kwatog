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
            $table->unsignedInteger('inspected_quantity')->nullable();
            $table->boolean('critical_defect')->nullable();
            $table->boolean('defects_removed_confirmed')->nullable();
        });
        Schema::table('non_conformance_reports', function (Blueprint $table): void {
            $table->decimal('sorting_inspected_quantity', 15, 3)->nullable();
            $table->string('sorting_evidence_reference', 500)->nullable();
            $table->foreignId('sorting_performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('sorting_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('sorting_approved_at')->nullable();
        });
        Schema::table('material_review_records', function (Blueprint $table): void {
            $table->decimal('release_inspected_quantity', 15, 3)->nullable();
            $table->string('release_evidence_reference', 500)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inspections ADD CONSTRAINT inspections_coverage_bounds CHECK (inspected_quantity IS NULL OR (inspected_quantity >= sample_size AND inspected_quantity <= batch_quantity AND sample_defect_count IS NOT NULL AND sample_defect_count BETWEEN 0 AND inspected_quantity))');
            DB::statement('ALTER TABLE non_conformance_reports ADD CONSTRAINT ncr_sorting_evidence_bounds CHECK (sorting_inspected_quantity IS NULL OR (sorting_inspected_quantity > 0 AND sorting_evidence_reference IS NOT NULL AND sorting_performed_by IS NOT NULL AND sorting_approved_by IS NOT NULL AND sorting_performed_by <> sorting_approved_by AND sorting_approved_at IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inspections DROP CONSTRAINT IF EXISTS inspections_coverage_bounds');
            DB::statement('ALTER TABLE non_conformance_reports DROP CONSTRAINT IF EXISTS ncr_sorting_evidence_bounds');
        }
        Schema::table('material_review_records', fn (Blueprint $table) => $table->dropColumn(['release_inspected_quantity', 'release_evidence_reference']));
        Schema::table('non_conformance_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sorting_performed_by');
            $table->dropConstrainedForeignId('sorting_approved_by');
            $table->dropColumn(['sorting_inspected_quantity', 'sorting_evidence_reference', 'sorting_approved_at']);
        });
        Schema::table('inspections', fn (Blueprint $table) => $table->dropColumn(['inspected_quantity', 'critical_defect', 'defects_removed_confirmed']));
    }
};
