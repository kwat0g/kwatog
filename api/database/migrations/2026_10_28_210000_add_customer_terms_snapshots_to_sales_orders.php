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
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('payment_method')->nullable();
            $table->jsonb('customer_terms_snapshot')->nullable();
        });

        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->jsonb('warranty_terms_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('sales_orders')->whereNotNull('payment_method')->orWhereNotNull('customer_terms_snapshot')->exists()
            || DB::table('sales_order_items')->whereNotNull('warranty_terms_snapshot')->exists()) {
            throw new RuntimeException('Cannot roll back sales-order term snapshots while order data uses them. Export or migrate that data first.');
        }

        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropColumn('warranty_terms_snapshot');
        });
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn(['payment_method', 'customer_terms_snapshot']);
        });
    }
};
