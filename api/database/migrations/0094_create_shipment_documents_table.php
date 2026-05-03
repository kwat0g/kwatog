<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 65. Per-shipment customs / shipping documents.
 *
 * The 9 import document types we track:
 *   proforma_invoice, commercial_invoice, packing_list, bill_of_lading,
 *   import_entry, certificate_of_origin, msds, boc_release, insurance_certificate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('file_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->index(['shipment_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_documents');
    }
};
