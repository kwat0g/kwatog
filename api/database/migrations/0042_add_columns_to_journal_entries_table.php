<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 / Task 32 — extend the journal_entries table with reversal tracking.
 *
 * The original Sprint-3 migration (0039) created the bones of the table so the
 * payroll GL posting service could write into it. This adds the reversal FK
 * and a CHECK constraint on lines so we can never persist a row that's both
 * debit AND credit (or neither).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_entries')
            && ! Schema::hasColumn('journal_entries', 'reversed_by_entry_id')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->foreignId('reversed_by_entry_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('journal_entries')
                    ->nullOnDelete();
                $table->index('reversed_by_entry_id');
            });
        }

        // PostgreSQL CHECK: each line must be either pure debit or pure credit, not both, not neither.
        if (Schema::hasTable('journal_entry_lines') && config('database.default') === 'pgsql') {
            $exists = collect(\DB::select(
                "SELECT 1 FROM pg_constraint WHERE conname = 'jel_debit_xor_credit_chk'"
            ))->isNotEmpty();
            if (! $exists) {
                \DB::statement(<<<'SQL'
                    ALTER TABLE journal_entry_lines
                    ADD CONSTRAINT jel_debit_xor_credit_chk
                    CHECK (
                        (debit  > 0 AND credit = 0)
                     OR (debit  = 0 AND credit > 0)
                    )
                SQL);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('journal_entry_lines') && config('database.default') === 'pgsql') {
            \DB::statement('ALTER TABLE journal_entry_lines DROP CONSTRAINT IF EXISTS jel_debit_xor_credit_chk');
        }
        if (Schema::hasTable('journal_entries') && Schema::hasColumn('journal_entries', 'reversed_by_entry_id')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropForeign(['reversed_by_entry_id']);
                $table->dropColumn('reversed_by_entry_id');
            });
        }
    }
};
