<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV3 — Batch + Lot traceability (IATF 16949).
 *
 * Decisions (per adviser-tasks plan, signed off by maintainer):
 *  - Skip the doc's `production_batches` table. A WO IS a batch; we only
 *    need a `batch_number` on `work_orders` and a `material_lot_references`
 *    JSON column for traceability backwards to GRN lots.
 *  - Lot fields on `grn_items` (line-level), NOT on `goods_receipt_notes`,
 *    so a single GRN can receive items from multiple supplier lots.
 *  - `shipment_lots` is genuinely new: links one Delivery to N WO batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('batch_number', 30)->nullable()->after('wo_number');
            $table->json('material_lot_references')->nullable();
            $table->unique('batch_number', 'work_orders_batch_number_unique');
        });

        Schema::table('grn_items', function (Blueprint $table) {
            $table->string('material_lot_number', 50)->nullable()->after('quantity_accepted');
            $table->string('supplier_lot_reference', 100)->nullable()->after('material_lot_number');
            $table->index('material_lot_number', 'grn_items_material_lot_number_index');
        });

        Schema::create('shipment_lots', function (Blueprint $table) {
            $table->id();
            $table->string('lot_number', 30)->unique();
            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            // Array of work_order ids (= batches) included in this shipment lot.
            $table->json('work_order_ids')->nullable();
            $table->unsignedInteger('quantity');
            $table->date('lot_date');
            $table->string('coc_path', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('delivery_id');
            $table->index('customer_id');
            $table->index('lot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lots');

        Schema::table('grn_items', function (Blueprint $table) {
            $table->dropIndex('grn_items_material_lot_number_index');
            $table->dropColumn(['material_lot_number', 'supplier_lot_reference']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropUnique('work_orders_batch_number_unique');
            $table->dropColumn(['batch_number', 'material_lot_references']);
        });
    }
};
