<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('de_minimis_benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('benefit_type', 50);               // DeMinimisBenefitType enum value
            $table->decimal('amount', 15, 2)->default(0.00);
            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->nullOnDelete();
            $table->unsignedInteger('period_year');
            $table->unsignedTinyInteger('period_month');       // 1-12
            $table->boolean('is_taxable_portion')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            // One employee cannot have two same-type entries for the same month-year combos
            // (but they can have multiple entries for different benefit types).
            $table->unique(
                ['employee_id', 'benefit_type', 'period_year', 'period_month', 'is_taxable_portion'],
                'de_minimis_unique_employee_month',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('de_minimis_benefits');
    }
};
