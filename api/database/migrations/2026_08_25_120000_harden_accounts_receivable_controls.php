<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->nullable()->unique();
        });

        Schema::table('official_receipts', function (Blueprint $table): void {
            $table->unique('collection_id', 'official_receipts_collection_id_unique');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreign('sales_order_id')->references('id')->on('sales_orders')->nullOnDelete();
            $table->foreign('delivery_id')->references('id')->on('deliveries')->nullOnDelete();
            $table->index(['status', 'date', 'cancelled_at'], 'invoices_ar_history_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_ar_history_idx');
            $table->dropForeign(['delivery_id']);
            $table->dropForeign(['sales_order_id']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
        });

        Schema::table('official_receipts', function (Blueprint $table): void {
            $table->dropUnique('official_receipts_collection_id_unique');
        });

        Schema::table('collections', function (Blueprint $table): void {
            $table->dropUnique('collections_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
