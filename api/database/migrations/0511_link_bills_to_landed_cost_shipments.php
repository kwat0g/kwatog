<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->foreignId('landed_cost_shipment_id')
                ->nullable()
                ->after('goods_receipt_note_id')
                ->constrained('shipments')
                ->nullOnDelete();
            $table->index('landed_cost_shipment_id');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('landed_cost_shipment_id');
        });
    }
};
