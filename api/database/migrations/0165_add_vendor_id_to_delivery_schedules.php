<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV10 — B2B Portals. Extend delivery_schedules for supplier-side usage.
 *
 * Suppliers submit delivery schedules linked to their POs, while customers
 * submit schedules linked to their customer account. Both use the same table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_schedules', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete()->after('customer_id');
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete()->after('vendor_id');

            $table->index('vendor_id');
            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_schedules', function (Blueprint $table) {
            $table->dropIndex(['vendor_id']);
            $table->dropIndex(['purchase_order_id']);
            $table->dropForeign(['vendor_id']);
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn(['vendor_id', 'purchase_order_id']);
        });
    }
};
