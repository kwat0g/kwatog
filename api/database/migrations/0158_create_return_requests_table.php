<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV12 — Return Management (RMA).
 *
 * return_requests is the unified RMA root for both customer returns (from
 * Sales Orders / Invoices) and supplier returns (to Purchase Orders / Bills).
 *
 * Workflow: draft → pending_approval → approved → received → inspected → completed
 *           (any active status) → cancelled / rejected
 *
 * Stock impact:
 *   customer_return — add stock back into a warehouse location
 *   supplier_return — remove stock from warehouse (return_to_vendor movement)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();

            // RMA reference number, e.g. RMA-2026-0001
            $table->string('rma_number', 50)->unique();

            // ── Type ────────────────────────────────────────────
            $table->string('type', 20);  // customer_return | supplier_return

            // ── Status ──────────────────────────────────────────
            $table->string('status', 30)->default('draft');

            // ── Source document (polymorphic link) ──────────────
            // customer_return → sales_order_id OR invoice_id (one side of the
            // transaction the customer is returning goods from).
            // supplier_return → purchase_order_id OR bill_id.
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();

            // ── Parties ─────────────────────────────────────────
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();

            // ── Return details ──────────────────────────────────
            $table->string('reason_code', 30)->nullable();  // defective, damaged, wrong_item, excess, customer_change, quality_issue, other
            $table->text('reason_description')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();

            // ── Resolution / financials ─────────────────────────
            $table->string('resolution', 30)->nullable();  // replace, refund, credit_note, scrap, return_to_vendor
            $table->foreignId('credit_note_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('replacement_wo_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->decimal('refund_amount', 14, 2)->nullable();

            // ── Stock impact side-effect (written after completion) ──
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();

            // ── Dates ───────────────────────────────────────────
            $table->date('return_date')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // ── Auditors ────────────────────────────────────────
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        // ── Return request items (the physical goods being returned) ──
        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('returned_quantity', 12, 3)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('reason')->nullable();
            $table->string('condition', 30)->nullable(); // new, used, damaged, defective, obsolete
            $table->decimal('stock_movement_quantity', 12, 3)->default(0); // actual qty moved

            $table->foreignId('source_sales_order_item_id')->nullable()->constrained('sales_order_items')->nullOnDelete();
            $table->foreignId('source_invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
            $table->foreignId('source_po_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->foreignId('source_bill_item_id')->nullable()->constrained('bill_items')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_request_items');
        Schema::dropIfExists('return_requests');
    }
};
