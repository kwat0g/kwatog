<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 50. Append-only mold lifecycle log.
 * Used by Sprint 8 Task 69 (maintenance) to read past resets and projected
 * remaining life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mold_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mold_id')->constrained('molds')->cascadeOnDelete();
            $table->string('event_type', 30); // created / maintenance_completed / shot_limit_reached / retired / repaired
            $table->text('description')->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->string('performed_by', 100)->nullable();
            $table->date('event_date');
            $table->unsignedInteger('shot_count_at_event');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['mold_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mold_history');
    }
};
