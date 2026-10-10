<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_shipment_id')->constrained('supplier_shipments')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
            $table->decimal('quantity_shipped', 12, 2);
            $table->timestamps();
            $table->unique(['supplier_shipment_id', 'purchase_order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_shipment_items');
    }
};
