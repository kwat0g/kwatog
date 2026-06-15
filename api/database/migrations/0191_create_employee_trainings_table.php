<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('training_id')->constrained('trainings')->restrictOnDelete();
            $table->date('scheduled_for')->nullable();
            $table->date('completed_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->string('certificate_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('last_alert_level', 10)->nullable();
            $table->timestamp('last_alert_at')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'training_id', 'scheduled_for'], 'uq_emp_training_scheduled');
            $table->index('employee_id', 'ix_emp_training_employee');
            $table->index(['status', 'expires_at'], 'ix_emp_training_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_trainings');
    }
};
