<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV10 — stores supplier-uploaded shipping documents (commercial invoice,
 * packing list, B/L, supplier invoice) linked to purchase orders.
 * Distinct from the Series E vault (system-generated PDFs) and shipment_documents
 * (admin-uploaded import docs).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('portal_shipping_documents', function (Blueprint $table) {
            $table->id();

            // Polymorphic-ish: always linked to a PO; optionally to a Bill
            // (for supplier-submitted invoices).
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete();

            // Document type classification
            $table->string('document_type', 50); // commercial_invoice, packing_list, bill_of_lading, supplier_invoice, other

            // File storage
            $table->string('file_path', 500);
            $table->string('original_filename', 200);
            $table->unsignedInteger('file_size_bytes')->default(0);
            $table->string('mime_type', 100)->nullable();

            // Optional notes from uploader
            $table->string('notes', 500)->nullable();

            // Audit — uploaded_by references the portal user (not the User table)
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('uploaded_at');

            $table->timestamps();

            $table->index(['purchase_order_id', 'document_type'], 'portal_docs_po_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_shipping_documents');
    }
};
