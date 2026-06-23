<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_year_end_leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->integer('year');
            $table->timestamp('processed_at');
            $table->foreignId('processed_by')->constrained('users');
            $table->integer('employees_count')->default(0);
            $table->decimal('days_converted', 8, 1)->default(0);
            $table->decimal('days_forfeited', 8, 1)->default(0);
            $table->timestamps();

            // Unique constraint guarantees idempotency: one processed record
            // per leave type per year.
            $table->unique(['leave_type_id', 'year'], 'uq_processed_leave_type_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_year_end_leave_types');
    }
};
