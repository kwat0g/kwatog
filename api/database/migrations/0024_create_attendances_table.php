<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('shift_id')->nullable()->constrained('shifts');
            $table->timestamp('time_in')->nullable();
            $table->timestamp('time_out')->nullable();

            $table->decimal('regular_hours', 5, 2)->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->decimal('night_diff_hours', 5, 2)->default(0);
            $table->integer('tardiness_minutes')->default(0);
            $table->integer('undertime_minutes')->default(0);

            $table->string('holiday_type', 30)->nullable();
            $table->boolean('is_rest_day')->default(false);
            $table->decimal('day_type_rate', 5, 2)->default(1.00);
            $table->string('status', 20)->default('present');
            $table->boolean('is_manual_entry')->default(false);
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index('date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
