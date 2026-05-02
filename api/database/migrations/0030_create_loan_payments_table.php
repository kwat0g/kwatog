<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('employee_loans')->cascadeOnDelete();
            $table->unsignedBigInteger('payroll_id')->nullable()->index();
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_type', 20)->default('payroll_deduction'); // payroll_deduction|manual|final_pay
            $table->string('remarks')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['loan_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
    }
};
