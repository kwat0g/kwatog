<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('purchase_request_item_id')->nullable()->constrained('purchase_request_items')->nullOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total', 15, 2);
            $table->decimal('quantity_received', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['purchase_order_id', 'item_id']);
            $table->index('purchase_request_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
