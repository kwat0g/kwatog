<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OGAMI-011 — mid-cycle salary changes. Each row records the salary
        // that takes effect on `effective_date`. PayrollCalculatorService uses
        // these to prorate basic pay across a raise that lands inside a period.
        Schema::create('employee_salary_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('basic_monthly_salary', 15, 2);
            $table->decimal('daily_rate', 15, 2)->nullable();
            $table->date('effective_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_history');
    }
};
