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
            $table->boolean('sorting_uses_reviewed_qc')->default(false);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE non_conformance_reports DROP CONSTRAINT IF EXISTS ncr_sorting_evidence_bounds');
            DB::statement('ALTER TABLE non_conformance_reports ADD CONSTRAINT ncr_sorting_evidence_bounds CHECK (sorting_inspected_quantity IS NULL OR (sorting_inspected_quantity > 0 AND sorting_evidence_reference IS NOT NULL AND sorting_performed_by IS NOT NULL AND sorting_approved_by IS NOT NULL AND (sorting_performed_by <> sorting_approved_by OR sorting_uses_reviewed_qc) AND sorting_approved_at IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::table('non_conformance_reports')->whereNotNull('sorting_inspected_quantity')
            ->whereColumn('sorting_performed_by', 'sorting_approved_by')->exists()) {
            throw new RuntimeException('Cannot roll back while QC separation records use the new policy. Preserve these records and keep this migration applied.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE non_conformance_reports DROP CONSTRAINT IF EXISTS ncr_sorting_evidence_bounds');
            DB::statement('ALTER TABLE non_conformance_reports ADD CONSTRAINT ncr_sorting_evidence_bounds CHECK (sorting_inspected_quantity IS NULL OR (sorting_inspected_quantity > 0 AND sorting_evidence_reference IS NOT NULL AND sorting_performed_by IS NOT NULL AND sorting_approved_by IS NOT NULL AND sorting_performed_by <> sorting_approved_by AND sorting_approved_at IS NOT NULL))');
        }
        Schema::table('non_conformance_reports', fn (Blueprint $table) => $table->dropColumn('sorting_uses_reviewed_qc'));
    }
};
