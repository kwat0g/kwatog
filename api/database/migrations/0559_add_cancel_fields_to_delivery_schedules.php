<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier delivery schedules — cancellation support and partial scheduling fix.
 *
 * Removes the (vendor_id, purchase_order_id, month) unique index: a supplier who
 * scheduled item A of a PO could not then schedule item B for the same month.
 * Several plans per PO and month are now allowed; the quantity ceiling is
 * enforced by SupplierPoCapabilities::schedulableQuantities() under the PO lock.
 *
 * Adds cancel fields: cancelled_at, cancel_reason, cancelled_by_portal_user_id.
 * Cancelled schedules release their quantity for re-scheduling (handled by
 * SupplierPoCapabilities::openScheduledQuantities, which filters to open
 * statuses only).
 *
 * The customer (customer_id, month) unique index is left untouched — customer
 * delivery schedules remain one per month.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS delivery_schedules_vendor_po_month_unique');

        Schema::table('delivery_schedules', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('cancelled_by_portal_user_id')
                ->nullable()
                ->constrained('supplier_portal_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $duplicates = DB::table('delivery_schedules')
            ->whereNotNull('vendor_id')
            ->select('vendor_id', 'purchase_order_id', 'month')
            ->groupBy('vendor_id', 'purchase_order_id', 'month')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Cannot restore delivery_schedules_vendor_po_month_unique: some purchase orders now have several schedules in one month.');
        }

        Schema::table('delivery_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by_portal_user_id');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX delivery_schedules_vendor_po_month_unique
             ON delivery_schedules (vendor_id, purchase_order_id, month)
             WHERE vendor_id IS NOT NULL'
        );
    }
};
