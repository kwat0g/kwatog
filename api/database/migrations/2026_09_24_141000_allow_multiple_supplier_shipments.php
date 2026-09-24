<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove unique constraint on purchase_order_id to allow multiple shipments per PO.
        // Each shipment gets its own carrier, tracking, and ETA; updates no longer overwrite.
        Schema::table('supplier_shipments', function (Blueprint $table): void {
            $table->dropUnique(['purchase_order_id']);
            // Replace with a plain index for efficient lookups.
            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_shipments', function (Blueprint $table): void {
            // Only re-add the unique if no PO has >1 shipment.
            $poCount = \DB::table('supplier_shipments')
                ->groupBy('purchase_order_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($poCount > 0) {
                throw new \RuntimeException(
                    'Cannot rollback: one or more purchase orders have multiple shipments. '
                    . 'Delete the extra rows first or preserve this migration.'
                );
            }

            $table->dropIndex(['purchase_order_id']);
            $table->unique('purchase_order_id');
        });
    }
};
