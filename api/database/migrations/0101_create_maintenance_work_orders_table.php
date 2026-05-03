<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — Task 69. Maintenance work orders.
 *
 * type=preventive  : auto-generated from a maintenance_schedule
 * type=corrective  : created on machine breakdown (also auto-linked from
 *                    machine_downtimes.maintenance_order_id when present)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('mwo_number', 32)->unique();
            $table->string('maintainable_type', 50);
            $table->unsignedBigInteger('maintainable_id');
            $table->foreignId('schedule_id')->nullable()->constrained('maintenance_schedules')->nullOnDelete();
            $table->string('type', 20);                // preventive | corrective
            $table->string('priority', 20)->default('medium'); // critical | high | medium | low
            $table->text('description');
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('open'); // open | assigned | in_progress | completed | cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('downtime_minutes')->default(0);
            $table->decimal('cost', 15, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['maintainable_type', 'maintainable_id']);
            $table->index('type');
            $table->index('priority');
            $table->index('status');
            $table->index('assigned_to');
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_work_orders');
    }
};
