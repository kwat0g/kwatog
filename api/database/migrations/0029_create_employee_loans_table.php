<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_no', 20)->unique();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('loan_type', 20); // company_loan | cash_advance
            $table->decimal('principal', 15, 2);
            $table->decimal('interest_rate', 5, 2)->default(0.00);
            $table->decimal('monthly_amortization', 15, 2);
            $table->decimal('total_paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('pay_periods_total');
            $table->integer('pay_periods_remaining');
            $table->integer('approval_chain_size')->default(0);
            $table->text('purpose')->nullable();
            $table->string('status', 20)->default('pending'); // pending|active|paid|cancelled|rejected
            $table->boolean('is_final_pay_deduction')->default(false);
            $table->timestamps();

            $table->index(['employee_id', 'loan_type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_loans');
    }
};
