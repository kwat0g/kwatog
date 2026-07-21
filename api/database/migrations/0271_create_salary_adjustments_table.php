<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Snapshot of pay BEFORE the change (for audit + rejection safety).
            $table->decimal('from_basic_monthly_salary', 15, 2)->nullable();
            $table->decimal('from_daily_rate', 15, 2)->nullable();
            // Requested NEW pay — applied only on full approval.
            $table->decimal('to_basic_monthly_salary', 15, 2)->nullable();
            $table->decimal('to_daily_rate', 15, 2)->nullable();
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_adjustments');
    }
};
