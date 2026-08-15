<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B — shipping-document upload idempotency.
 *
 * `uploadShippingDocument` guards against stacking duplicate rows when the
 * same file is re-uploaded (portal double-click / retried request) via a
 * lock-then-guard on (purchase_order_id, document_type, original_filename,
 * file_size_bytes). This unique index is the DB backstop for that guard —
 * all four columns are NOT NULL, so a plain unique index suffices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_shipping_documents', function (Blueprint $table) {
            $table->unique(
                ['purchase_order_id', 'document_type', 'original_filename', 'file_size_bytes'],
                'portal_shipping_documents_dedup_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('portal_shipping_documents', function (Blueprint $table) {
            $table->dropUnique('portal_shipping_documents_dedup_unique');
        });
    }
};
