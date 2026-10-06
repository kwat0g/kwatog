<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('attendance_id')->nullable()->constrained('attendances');
            $table->string('direction', 3);
            $table->timestamp('punched_at');
            $table->decimal('latitude', 9, 6)->nullable();   // nulled after 90 days
            $table->decimal('longitude', 9, 6)->nullable();  // nulled after 90 days
            $table->decimal('accuracy_m', 7, 2);
            $table->decimal('distance_m', 9, 2);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'punched_at']);
            $table->index('attendance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
