<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-08 — Link bills back to the goods receipt that sourced them.
 *
 * The auto-bill chain (GRN accepted → draft supplier bill) needs a
 * traceable FK so the GRN detail page can surface "bill auto-created
 * from this receipt" and so the listener can stay idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bills', 'goods_receipt_note_id')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('goods_receipt_note_id')->nullable()->after('purchase_order_id')
                ->constrained('goods_receipt_notes')->nullOnDelete();
            $table->index('goods_receipt_note_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bills', 'goods_receipt_note_id')) {
            return;
        }
        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['goods_receipt_note_id']);
            $table->dropIndex(['goods_receipt_note_id']);
            $table->dropColumn('goods_receipt_note_id');
        });
    }
};
