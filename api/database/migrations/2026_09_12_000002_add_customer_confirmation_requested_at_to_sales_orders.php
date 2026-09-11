<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separates "this draft order is visible to the customer" from "this draft
 * order exists" (2026-09-12).
 *
 * A portal-placed or internally-raised sales order is a draft until the
 * customer is actually asked to review it. Without this column the portal
 * would have to treat every draft as negotiable, letting a customer respond
 * to an order the sales team has not yet sent them. `requestCustomerConfirmation`
 * stamps it; the customer response endpoint refuses a draft without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_orders') && ! Schema::hasColumn('sales_orders', 'customer_confirmation_requested_at')) {
            Schema::table('sales_orders', function (Blueprint $table): void {
                $table->timestamp('customer_confirmation_requested_at')->nullable()->after('confirmed_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_orders') && Schema::hasColumn('sales_orders', 'customer_confirmation_requested_at')) {
            Schema::table('sales_orders', function (Blueprint $table): void {
                $table->dropColumn('customer_confirmation_requested_at');
            });
        }
    }
};
