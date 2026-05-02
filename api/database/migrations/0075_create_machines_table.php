<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 50. Production machines (injection molders).
 *
 * Note: SCHEMA.md lists current_work_order_id as a FK to work_orders. That
 * table is introduced in Task 51 — so we declare the column here as a plain
 * unsigned big int and add the FK constraint in 0076 (or whichever Task 51
 * migration creates work_orders) once both tables exist. Same pattern used
 * for sales_orders.mrp_plan_id in Task 48.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('machine_code', 20)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('tonnage')->nullable();
            $table->string('machine_type', 50)->default('injection_molder');
            $table->decimal('operators_required', 3, 1)->default(1.0);
            $table->decimal('available_hours_per_day', 4, 1)->default(16.0);
            $table->string('status', 20)->default('idle'); // running/idle/maintenance/breakdown/offline
            $table->unsignedBigInteger('current_work_order_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
