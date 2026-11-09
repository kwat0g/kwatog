<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_agreement_documents', function (Blueprint $table): void {
            $table->string('signature_status', 20)->default('pending');
            $table->string('document_sha256', 64)->nullable();
            $table->foreignId('template_id')->nullable()->constrained('portal_contract_templates')->nullOnDelete();
            $table->unsignedInteger('template_version')->nullable();
            $table->foreignId('signed_by_portal_user_id')->nullable()->constrained('customer_portal_users')->nullOnDelete();
            $table->string('signed_name', 200)->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->string('signed_document_sha256', 64)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('issued_at')->nullable();
            $table->index(['customer_id', 'signature_status']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_agreement_documents', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'signature_status']);
            $table->dropConstrainedForeignId('signed_by_portal_user_id');
            $table->dropConstrainedForeignId('template_id');
            $table->dropConstrainedForeignId('issued_by');
            $table->dropColumn([
                'signature_status', 'document_sha256', 'template_version', 'signed_name', 'signed_at', 'signed_document_sha256', 'issued_at',
            ]);
        });
    }
};
