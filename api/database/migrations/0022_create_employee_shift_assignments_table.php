<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts');
            $table->date('effective_date');
            $table->date('end_date')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_id', 'effective_date']);
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shift_assignments');
    }
};
