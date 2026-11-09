<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_agreement_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('portal_contract_templates')->nullOnDelete();
            $table->unsignedInteger('template_version')->nullable();
            $table->string('file_path');
            $table->string('filename', 255);
            $table->unsignedBigInteger('size');
            $table->string('mime', 100)->default('application/pdf');
            $table->string('document_sha256', 64)->nullable();
            $table->string('signature_status', 20)->default('pending');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('issued_at')->nullable();
            $table->foreignId('signed_by_portal_user_id')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->string('signed_name', 200)->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->string('signed_document_sha256', 64)->nullable();
            $table->timestampsTz();
            $table->index(['vendor_id', 'created_at']);
            $table->index(['vendor_id', 'signature_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_agreement_documents');
    }
};
