<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods');
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('original_payroll_id')->constrained('payrolls');
            $table->string('type', 20); // underpayment | overpayment
            $table->decimal('amount', 15, 2); // positive number; type controls sign at apply time
            $table->text('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|applied
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_to_payroll_id')->nullable()->constrained('payrolls');
            $table->timestamps();

            $table->index('payroll_period_id');
            $table->index('employee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
    }
};
