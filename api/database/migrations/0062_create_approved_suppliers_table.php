<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approved_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->boolean('is_preferred')->default(false);
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->decimal('last_price', 15, 2)->nullable();
            $table->timestamp('last_price_at')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'vendor_id'], 'approved_suppliers_item_vendor_unique');
            $table->index('is_preferred');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approved_suppliers');
    }
};
