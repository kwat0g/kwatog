<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wo_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('routing_operation_id')->nullable()->constrained('routing_operations')->nullOnDelete();
            $table->integer('sequence');
            $table->string('operation_name', 100);
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->foreignId('mold_id')->nullable()->constrained('molds')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('planned_start')->nullable();
            $table->timestamp('planned_end')->nullable();
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->timestamp('setup_start')->nullable();
            $table->timestamp('setup_end')->nullable();
            $table->decimal('qty_planned', 15, 4);
            $table->decimal('qty_completed', 15, 4)->default(0);
            $table->decimal('qty_scrapped', 15, 4)->default(0);
            $table->string('scrap_reason', 255)->nullable();
            $table->decimal('downtime_minutes', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_operations');
    }
};
