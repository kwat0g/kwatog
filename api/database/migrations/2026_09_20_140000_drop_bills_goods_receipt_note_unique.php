<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow partial-bill continuation for partially-accepted GRNs.
 * Idempotency is enforced by BillService under row lock, calculating unbilled accepted quantities.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bills') && Schema::hasIndex('bills', 'bills_goods_receipt_note_unique')) {
            Schema::table('bills', fn (Blueprint $table) => $table->dropUnique('bills_goods_receipt_note_unique'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bills') && ! Schema::hasIndex('bills', 'bills_goods_receipt_note_unique')) {
            Schema::table('bills', fn (Blueprint $table) => $table->unique('goods_receipt_note_id', 'bills_goods_receipt_note_unique'));
        }
    }
};
