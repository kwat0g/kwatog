<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series E (Task E3) — central document vault. Polymorphic so any record
 * type (payslip, invoice, PO, CoC, payroll register, gov remittances, etc.)
 * can attach without a per-entity table. Distinct from `employee_documents`
 * and `shipment_documents` which are user-uploaded attachments with
 * different lifecycle and ACL semantics.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // What kind of document (payslip, invoice, etc.)
            $table->string('document_type', 50);

            // Polymorphic entity reference (Payroll, Invoice, PurchaseOrder,
            // PayrollPeriod, Inspection, Delivery, WorkOrder, Employee, etc.)
            // We store the FQN unless a morph map is set.
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');

            // Storage details
            $table->string('file_path', 500);
            $table->string('file_name', 200);
            $table->unsignedInteger('file_size')->default(0);
            $table->string('mime_type', 100)->default('application/pdf');

            // Audit
            $table->foreignId('generated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');

            // Confidentiality flag — drives watermark + Cache-Control: no-store
            $table->boolean('is_confidential')->default(false);

            // SHA-256 checksum for tamper detection
            $table->string('checksum_sha256', 64)->nullable();

            // Soft delete to preserve audit history when blob is purged.
            $table->softDeletes();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'documents_entity_idx');
            $table->index(['document_type', 'generated_at'], 'documents_type_time_idx');
            $table->index('generated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
