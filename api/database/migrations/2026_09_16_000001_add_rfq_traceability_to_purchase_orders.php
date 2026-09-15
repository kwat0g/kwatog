<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignId('request_for_quote_id')->nullable()->after('purchase_request_id')->constrained('request_for_quotes')->nullOnDelete();
            $table->index('request_for_quote_id');
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->foreignId('rfq_award_id')->nullable()->after('purchase_request_item_id')->constrained('rfq_awards')->nullOnDelete();
            $table->foreignId('supplier_quote_version_id')->nullable()->after('rfq_award_id')->constrained('supplier_quotes')->nullOnDelete();
            $table->index(['rfq_award_id', 'supplier_quote_version_id'], 'po_items_rfq_trace_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropIndex('po_items_rfq_trace_idx');
            $table->dropConstrainedForeignId('supplier_quote_version_id');
            $table->dropConstrainedForeignId('rfq_award_id');
        });
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex(['request_for_quote_id']);
            $table->dropConstrainedForeignId('request_for_quote_id');
        });
    }
};
