<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_request_items', function (Blueprint $table) {
            $table->foreignId('source_grn_item_id')
                ->nullable()
                ->after('source_po_item_id')
                ->constrained('grn_items')
                ->nullOnDelete();
        });

        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreignId('replacement_purchase_order_id')
                ->nullable()
                ->after('replacement_wo_id')
                ->constrained('purchase_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replacement_purchase_order_id');
        });

        Schema::table('return_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_grn_item_id');
        });
    }
};
