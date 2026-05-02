<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thirteenth_month_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->integer('year');
            $table->decimal('total_basic_earned', 15, 2)->default(0);
            $table->decimal('accrued_amount', 15, 2)->default(0);
            $table->boolean('is_paid')->default(false);
            $table->date('paid_date')->nullable();
            $table->foreignId('payroll_id')->nullable()->constrained('payrolls');
            $table->timestamps();

            $table->unique(['employee_id', 'year']);
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thirteenth_month_accruals');
    }
};
