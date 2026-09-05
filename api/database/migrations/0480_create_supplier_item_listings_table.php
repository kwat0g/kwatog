<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_item_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('supplier_item_code', 100)->nullable();
            $table->string('supplier_item_name', 255)->nullable();
            $table->decimal('price', 15, 2);
            $table->string('order_uom', 20)->nullable();
            $table->decimal('base_qty_per_order_unit', 12, 4)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['item_id', 'status']);
            $table->index('status');
        });

        Schema::table('approved_suppliers', function (Blueprint $table) {
            $table->string('supplier_item_code', 100)->nullable();
            $table->string('supplier_item_name', 255)->nullable();
            $table->string('order_uom', 20)->nullable();
            $table->decimal('base_qty_per_order_unit', 12, 4)->nullable();
            $table->date('price_valid_until')->nullable();
            $table->foreignId('supplier_listing_id')
                ->nullable()
                ->constrained('supplier_item_listings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approved_suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_listing_id');
            $table->dropColumn([
                'supplier_item_code',
                'supplier_item_name',
                'order_uom',
                'base_qty_per_order_unit',
                'price_valid_until',
            ]);
        });
        Schema::dropIfExists('supplier_item_listings');
    }
};
