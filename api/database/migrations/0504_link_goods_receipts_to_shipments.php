<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table): void {
            $table->foreignId('shipment_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained('shipments')
                ->nullOnDelete();
            $table->unique('shipment_id', 'goods_receipt_notes_shipment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table): void {
            $table->dropUnique('goods_receipt_notes_shipment_unique');
            $table->dropConstrainedForeignId('shipment_id');
        });
    }
};
