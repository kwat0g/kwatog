<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — Task 69. Spare-part consumption per maintenance WO.
 *
 * Each row corresponds to a `stock_movements` row (movement_type='maintenance_issue')
 * deducting the spare-part item from inventory. Item is always item_type=spare_part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spare_part_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('maintenance_work_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('total_cost', 15, 2);
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('work_order_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spare_part_usage');
    }
};
