<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 51. Materials reserved/issued per work order.
 * BomService::explode() expansion lands here at WO creation; reservations
 * and issues update actual_quantity_issued / variance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('bom_quantity', 15, 3);
            $table->decimal('actual_quantity_issued', 15, 3)->default(0);
            $table->decimal('variance', 15, 3)->default(0);

            $table->index('work_order_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_materials');
    }
};
