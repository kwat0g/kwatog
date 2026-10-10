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
        Schema::create('late_production_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_order_crew_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('source_reference', 120);
            $table->timestampTz('production_started_at');
            $table->timestampTz('production_finished_at');
            $table->unsignedInteger('good_count');
            $table->unsignedInteger('reject_count');
            $table->jsonb('defects')->default('[]');
            $table->text('reason');
            $table->jsonb('crew_snapshot');
            $table->jsonb('evidence_summary');
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 128);
            $table->char('idempotency_fingerprint', 64);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->foreignId('work_order_output_id')->nullable()->unique()->constrained('work_order_outputs')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['work_order_id', 'idempotency_key'], 'late_production_reports_retry_unique');
            $table->index(['work_order_id', 'status', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX late_production_reports_source_reference_unique ON late_production_reports (work_order_id, lower(source_reference))');
        DB::statement('ALTER TABLE late_production_reports ADD CONSTRAINT late_production_reports_counts_check CHECK ((good_count + reject_count) > 0)');
        DB::statement('ALTER TABLE late_production_reports ADD CONSTRAINT late_production_reports_interval_check CHECK (production_finished_at > production_started_at)');
        DB::statement("ALTER TABLE late_production_reports ADD CONSTRAINT late_production_reports_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'correction'))");
        DB::statement("ALTER TABLE late_production_reports ADD CONSTRAINT late_production_reports_review_check CHECK (((status IN ('pending', 'correction')) AND reviewed_by IS NULL AND reviewed_at IS NULL AND work_order_output_id IS NULL) OR (status = 'rejected' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND work_order_output_id IS NULL) OR (status = 'approved' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND work_order_output_id IS NOT NULL))");
    }

    public function down(): void
    {
        if (Schema::hasTable('late_production_reports') && DB::table('late_production_reports')->exists()) {
            throw new RuntimeException('Late production reports are production evidence and cannot be deleted by migration rollback.');
        }

        Schema::dropIfExists('late_production_reports');
    }
};
