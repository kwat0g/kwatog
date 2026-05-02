<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('pay_type', 10);

            // Volume & basic pay
            $table->decimal('days_worked', 4, 1)->nullable();
            $table->decimal('basic_pay',          15, 2)->default(0);
            $table->decimal('overtime_pay',       15, 2)->default(0);
            $table->decimal('night_diff_pay',     15, 2)->default(0);
            $table->decimal('holiday_pay',        15, 2)->default(0);
            $table->decimal('gross_pay',          15, 2)->default(0);

            // Government deductions (employee + employer share so the GL has both)
            $table->decimal('sss_ee',             15, 2)->default(0);
            $table->decimal('sss_er',             15, 2)->default(0);
            $table->decimal('philhealth_ee',      15, 2)->default(0);
            $table->decimal('philhealth_er',      15, 2)->default(0);
            $table->decimal('pagibig_ee',         15, 2)->default(0);
            $table->decimal('pagibig_er',         15, 2)->default(0);
            $table->decimal('withholding_tax',    15, 2)->default(0);

            // Deductions
            $table->decimal('loan_deductions',    15, 2)->default(0);
            $table->decimal('other_deductions',   15, 2)->default(0);
            $table->decimal('adjustment_amount',  15, 2)->default(0); // signed
            $table->decimal('total_deductions',   15, 2)->default(0);
            $table->decimal('net_pay',            15, 2)->default(0);

            // Batch processing metadata
            $table->text('error_message')->nullable();
            $table->timestamp('computed_at')->nullable();

            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
