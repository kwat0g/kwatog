<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PG does not auto-index FKs. grn_items.goods_receipt_note_id was covered by
// 2026_10_11_090100; these three location FKs on the hottest inventory tables
// were still unindexed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grn_items', function (Blueprint $table): void {
            $table->index('location_id');
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index('from_location_id');
            $table->index('to_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex(['from_location_id']);
            $table->dropIndex(['to_location_id']);
        });
        Schema::table('grn_items', function (Blueprint $table): void {
            $table->dropIndex(['location_id']);
        });
    }
};
