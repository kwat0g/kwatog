<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('production_purpose', 16)->nullable()->index();
            $table->uuid('stock_creation_request_key')->nullable()->unique();
            $table->string('stock_creation_fingerprint', 64)->nullable();
        });
        DB::table('work_orders')->whereNotNull('sales_order_id')->update(['production_purpose' => 'order']);
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->string('fulfillment_policy', 16)->nullable();
        });
        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->foreignId('stock_production_work_order_id')->nullable()->constrained('work_orders')->restrictOnDelete();
        });
        Schema::create('sales_order_stock_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_order_output_id')->constrained()->restrictOnDelete();
            $table->foreignId('inspection_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('lot_number');
            $table->date('expiry_date')->nullable();
            foreach (['quantity', 'transferred_quantity', 'consumed_quantity', 'released_quantity'] as $column) {
                $table->decimal($column, 18, 3)->default(0);
            }
            $table->string('status', 16)->default('reserved');
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->index();
            $table->string('payload_fingerprint', 64);
            $table->timestamps();
            $table->unique(['sales_order_item_id', 'work_order_output_id', 'location_id', 'request_key'], 'order_stock_source_request_unique');
        });
        DB::statement('ALTER TABLE sales_order_stock_allocations ADD CONSTRAINT order_stock_quantities_valid CHECK (quantity > 0 AND transferred_quantity >= 0 AND consumed_quantity >= 0 AND released_quantity >= 0 AND consumed_quantity <= transferred_quantity AND transferred_quantity + released_quantity <= quantity)');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT production_purpose_valid CHECK (production_purpose IS NULL OR production_purpose IN ('stock','order','internal'))");
        DB::statement("ALTER TABLE sales_order_items ADD CONSTRAINT fulfillment_policy_valid CHECK (fulfillment_policy IS NULL OR fulfillment_policy IN ('stock_first','produce_new'))");
        Schema::table('delivery_items', function (Blueprint $table): void {
            $table->foreignId('sales_order_stock_allocation_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->string('automatic_supply_key')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (DB::table('sales_order_stock_allocations')->exists()
            || DB::table('work_orders')->where('production_purpose', 'stock')->exists()
            || DB::table('sales_order_items')->whereNotNull('fulfillment_policy')->exists()
            || DB::table('purchase_requests')->whereNotNull('stock_production_work_order_id')->exists()) {
            throw new RuntimeException('Stock production or allocation history exists. This downgrade would remove provenance.');
        }
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropColumn('automatic_supply_key'));
        Schema::table('delivery_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_order_stock_allocation_id'));
        Schema::dropIfExists('sales_order_stock_allocations');
        Schema::table('purchase_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('stock_production_work_order_id'));
        DB::statement('ALTER TABLE sales_order_items DROP CONSTRAINT fulfillment_policy_valid');
        Schema::table('sales_order_items', fn (Blueprint $table) => $table->dropColumn('fulfillment_policy'));
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT production_purpose_valid');
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn(['production_purpose', 'stock_creation_request_key', 'stock_creation_fingerprint']));
    }
};
