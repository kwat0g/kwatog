<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('base_selling_price', 15, 2)->nullable();
            $table->decimal('minimum_selling_price', 15, 2)->nullable();
            $table->string('portal_visibility', 20)->default('restricted');
        });
        Schema::create('customer_product_access', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->primary(['product_id', 'customer_id']);
        });
        DB::table('customer_product_access')->insertUsing(['product_id', 'customer_id'],
            DB::table('product_price_agreements')->select('product_id', 'customer_id')->whereNull('deleted_at')->distinct());
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->string('price_source', 20)->nullable();
            $table->foreignId('price_agreement_id')->nullable()->constrained('product_price_agreements')->nullOnDelete();
            $table->date('pricing_date')->nullable();
            $table->unsignedInteger('price_tier_min_qty')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('price_agreement_id');
            $table->dropColumn(['price_source', 'pricing_date', 'price_tier_min_qty']);
        });
        Schema::dropIfExists('customer_product_access');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['base_selling_price', 'minimum_selling_price', 'portal_visibility']);
        });
    }
};
