<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — Task 69. Preventive maintenance schedules.
 *
 * Polymorphic: a schedule targets either a machine or a mold via
 * `maintainable_type` (`machine` | `mold`) + `maintainable_id`.
 *
 * Interval semantics:
 *   - hours: time-based (engine hours / running time)
 *   - days:  calendar-based
 *   - shots: mold cycle count (only valid for mold-type)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('maintainable_type', 50);   // machine | mold
            $table->unsignedBigInteger('maintainable_id');
            $table->string('schedule_type', 20)->default('preventive');
            $table->string('description', 200);
            $table->string('interval_type', 20);       // hours | days | shots
            $table->unsignedInteger('interval_value');
            $table->timestamp('last_performed_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['maintainable_type', 'maintainable_id']);
            $table->index('next_due_at');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_schedules');
    }
};
