<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy rows may point at a deleted or manually removed portal user.
        // Keep the document, but make the uploader nullable before adding the
        // referential constraint so the migration is recoverable in production.
        DB::table('portal_shipping_documents')
            ->whereNotNull('uploaded_by')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('supplier_portal_users')
                    ->whereColumn('supplier_portal_users.id', 'portal_shipping_documents.uploaded_by');
            })
            ->update(['uploaded_by' => null]);

        Schema::table('portal_shipping_documents', function (Blueprint $table): void {
            $table->dropUnique('portal_shipping_documents_dedup_unique');
            $table->char('content_sha256', 64)->nullable()->after('file_size_bytes');
            $table->unique(
                ['purchase_order_id', 'document_type', 'content_sha256'],
                'portal_shipping_documents_content_unique',
            );
            $table->foreign('uploaded_by', 'portal_shipping_documents_uploaded_by_fk')
                ->references('id')
                ->on('supplier_portal_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('portal_shipping_documents', function (Blueprint $table): void {
            $table->dropForeign('portal_shipping_documents_uploaded_by_fk');
            $table->dropUnique('portal_shipping_documents_content_unique');
            $table->dropColumn('content_sha256');
            $table->unique(
                ['purchase_order_id', 'document_type', 'original_filename', 'file_size_bytes'],
                'portal_shipping_documents_dedup_unique',
            );
        });
    }
};
