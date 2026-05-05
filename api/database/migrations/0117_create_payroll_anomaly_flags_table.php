<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task A9 — Payroll anomaly flags. Surfaced before HR finalises the period;
 * unresolved flags block finalisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_anomaly_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('flag_type', 40); // large_change | excessive_ot | high_deduction | first_payroll | zero_pay
            $table->json('details');         // {previous_value, current_value, percent_change, ...}
            $table->boolean('is_resolved')->default(false);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_remarks')->nullable();
            $table->timestamps();

            $table->index(['payroll_period_id', 'is_resolved']);
            $table->unique(['payroll_id', 'flag_type'], 'payroll_flag_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_anomaly_flags');
    }
};
