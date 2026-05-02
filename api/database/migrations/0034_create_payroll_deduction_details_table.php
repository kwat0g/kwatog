<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_deduction_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->string('deduction_type', 30); // sss|philhealth|pagibig|withholding_tax|loan|cash_advance|adjustment|other
            $table->string('description', 200)->nullable();
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('reference_id')->nullable(); // e.g. loan id

            $table->index('payroll_id');
            $table->index('deduction_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deduction_details');
    }
};
