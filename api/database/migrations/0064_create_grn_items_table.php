<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grn_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_note_id')->constrained('goods_receipt_notes')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->decimal('quantity_received', 15, 3);
            $table->decimal('quantity_accepted', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 4);
            $table->string('remarks', 200)->nullable();
            $table->timestamps();

            $table->index('purchase_order_item_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_items');
    }
};
