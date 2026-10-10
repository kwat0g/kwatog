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
            $table->foreignId('rework_ncr_id')->nullable()->constrained('non_conformance_reports');
            $table->foreignId('rework_source_output_id')->nullable()->constrained('work_order_outputs');
        });
        Schema::table('non_conformance_reports', function (Blueprint $table): void {
            $table->timestamp('rework_started_at')->nullable();
            $table->timestamp('rework_finished_at')->nullable();
            $table->foreignId('rework_started_by')->nullable()->constrained('users');
            $table->foreignId('rework_finished_by')->nullable()->constrained('users');
            $table->foreignId('rework_machine_id')->nullable()->constrained('machines');
            $table->foreignId('rework_mold_id')->nullable()->constrained('molds');
        });
    }

    public function down(): void
    {
        if (DB::table('work_order_outputs')->whereNotNull('rework_ncr_id')->orWhereNotNull('rework_source_output_id')->exists()
            || DB::table('non_conformance_reports')->whereNotNull('rework_started_at')->exists()) {
            throw new RuntimeException('Cannot remove inline repair lineage while repair evidence exists. Preserve the audit trail.');
        }
        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rework_ncr_id');
            $table->dropConstrainedForeignId('rework_source_output_id');
        });
        Schema::table('non_conformance_reports', function (Blueprint $table): void {
            foreach (['rework_started_by', 'rework_finished_by', 'rework_machine_id', 'rework_mold_id'] as $field) {
                $table->dropConstrainedForeignId($field);
            }
            $table->dropColumn(['rework_started_at', 'rework_finished_at']);
        });
    }
};
