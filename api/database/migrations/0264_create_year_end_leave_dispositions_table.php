<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REC-10 — per-employee year-end leave disposition.
 *
 * ProcessYearEndLeave is the single source of truth for what happens to each
 * employee's remaining leave at year-end: some days are converted (encashed),
 * some carried forward, some forfeited. This table records that decision per
 * (employee, leave_type, year) so:
 *   - ResetLeaveBalancesForYear can seed the next year from `days_carried`
 *     instead of re-reading the raw remaining balance (no double-handling), and
 *   - payroll can pay `cash_value` (already turned into an approved
 *     PayrollAdjustment by the job).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('year_end_leave_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('days_converted', 5, 1)->default(0);
            $table->decimal('days_carried', 5, 1)->default(0);
            $table->decimal('days_forfeited', 5, 1)->default(0);
            $table->decimal('cash_value', 15, 2)->default(0);
            $table->foreignId('payroll_adjustment_id')->nullable()->constrained('payroll_adjustments')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year'], 'yeld_emp_type_year_unique');
            $table->index(['leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('year_end_leave_dispositions');
    }
};
