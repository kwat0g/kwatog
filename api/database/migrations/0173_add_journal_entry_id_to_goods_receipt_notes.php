<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('goods_receipt_notes')) return;
        if (Schema::hasColumn('goods_receipt_notes', 'journal_entry_id')) return;

        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')
                ->nullable()
                ->after('rejected_reason')
                ->constrained('journal_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('goods_receipt_notes')) return;
        if (! Schema::hasColumn('goods_receipt_notes', 'journal_entry_id')) return;

        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
