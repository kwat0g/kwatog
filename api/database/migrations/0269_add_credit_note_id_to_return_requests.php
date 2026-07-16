<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REC-13 — repoint return_requests.credit_note_id from `invoices` (an unused
 * leftover FK from the negative-invoice era) to the real `credit_notes` table.
 * The column already exists (migration 0158); only the FK target changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the old FK to invoices if present, then constrain to credit_notes.
        Schema::table('return_requests', function (Blueprint $table) {
            try {
                $table->dropForeign(['credit_note_id']);
            } catch (\Throwable) {
                // FK name may differ across environments; ignore if absent.
            }
        });

        // Null any stale values that pointed at invoices (none in practice —
        // the column was never populated as an invoice FK).
        DB::table('return_requests')->update(['credit_note_id' => null]);

        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreign('credit_note_id')->references('id')->on('credit_notes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            try {
                $table->dropForeign(['credit_note_id']);
            } catch (\Throwable) {
            }
            $table->foreign('credit_note_id')->references('id')->on('invoices')->nullOnDelete();
        });
    }
};
