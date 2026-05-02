<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('change_type', 30); // hired|promoted|transferred|salary_adjusted|regularized|separated
            $table->json('from_value')->nullable();
            $table->json('to_value');
            $table->date('effective_date');
            $table->text('remarks')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_id', 'effective_date']);
            $table->index('change_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_history');
    }
};
