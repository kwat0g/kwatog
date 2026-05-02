<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 20)->nullable();
            $table->decimal('estimated_unit_price', 15, 2)->nullable();
            $table->string('purpose', 200)->nullable();
            $table->timestamps();

            $table->index('purchase_request_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
    }
};
