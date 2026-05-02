<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task 48. SO line items with per-line delivery_date.
 * MRP engine (Task 52) reads delivery_date - lead_time to schedule materials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total', 15, 2);
            $table->decimal('quantity_delivered', 10, 2)->default(0);
            $table->date('delivery_date');

            $table->index('sales_order_id');
            $table->index('product_id');
            $table->index('delivery_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
    }
};
