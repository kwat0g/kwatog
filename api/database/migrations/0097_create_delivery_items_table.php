<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 66. Per-line delivery row.
 *
 * Each row links to a sales_order_item and the outgoing-QC inspection
 * that gated it (Task 60). DeliveryService::create rejects items with no
 * passed outgoing inspection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->constrained('sales_order_items')->cascadeOnDelete();
            $table->foreignId('inspection_id')->nullable()->constrained('inspections')->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 15, 2);
            $table->timestamps();

            $table->index('delivery_id');
            $table->index('sales_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
    }
};
