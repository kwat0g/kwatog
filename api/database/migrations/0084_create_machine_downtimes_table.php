<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 51 + 56. Machine downtime ledger.
 * Used by OEE service (Task 57) to separate planned vs unplanned time.
 * maintenance_order_id FK is reserved for Sprint 8 Task 69.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_downtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->restrictOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('category', 30); // breakdown / changeover / material_shortage / no_order / planned_maintenance
            $table->text('description')->nullable();
            $table->unsignedBigInteger('maintenance_order_id')->nullable();
            $table->timestamps();

            $table->index(['machine_id', 'start_time']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_downtimes');
    }
};
