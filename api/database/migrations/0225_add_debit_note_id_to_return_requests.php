<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV12 + OGAMI-104 — Add debit_note_id FK to return_requests for supplier
 * return debit memos.  credit_note_id (Invoice FK, existing) covers customer
 * returns; debit_note_id (Bill FK) covers supplier returns so each FK targets
 * its correct table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreignId('debit_note_id')
                ->nullable()
                ->after('credit_note_id')
                ->constrained('bills')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('debit_note_id');
        });
    }
};
