<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2B — delivery-schedule idempotency + supplier-side fix.
 *
 * Migration 0165 extended `delivery_schedules` for supplier submissions
 * ("Suppliers submit delivery schedules linked to their POs... Both use the
 * same table") but left `customer_id` NOT NULL. A supplier submit only links
 * vendor_id + purchase_order_id, so every such insert violated the constraint
 * and returned a raw 500 — a demo-visible break on the supplier portal's
 * delivery-schedules page. Make `customer_id` nullable to match the
 * dual-portal design.
 *
 * Also adds partial unique indexes as the DB backstop for the lock-then-guard
 * idempotency in SupplierPortalService/CustomerPortalService — a portal
 * double-click or retried request can never stack duplicate rows:
 *   - one schedule per (vendor, purchase_order, month) for supplier rows
 *   - one schedule per (customer, month) for customer rows
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_schedules', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
        });

        // Dedupe any pre-existing stacked submissions (keep the earliest row)
        // so the unique indexes below can be created safely on a used DB.
        DB::statement(
            'DELETE FROM delivery_schedules a
             USING delivery_schedules b
             WHERE a.customer_id IS NOT NULL
               AND a.customer_id = b.customer_id
               AND a.month = b.month
               AND a.id > b.id'
        );

        DB::statement(
            'CREATE UNIQUE INDEX delivery_schedules_vendor_po_month_unique
             ON delivery_schedules (vendor_id, purchase_order_id, month)
             WHERE vendor_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX delivery_schedules_customer_month_unique
             ON delivery_schedules (customer_id, month)
             WHERE customer_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS delivery_schedules_customer_month_unique');
        DB::statement('DROP INDEX IF EXISTS delivery_schedules_vendor_po_month_unique');

        Schema::table('delivery_schedules', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable(false)->change();
        });
    }
};
