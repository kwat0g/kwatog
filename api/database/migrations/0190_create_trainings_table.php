<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('duration_hours', 5, 2)->nullable();
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->boolean('is_certification')->default(false);
            $table->foreignId('department_id')->nullable()
                ->constrained('departments')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('is_active', 'ix_trainings_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainings');
    }
};
