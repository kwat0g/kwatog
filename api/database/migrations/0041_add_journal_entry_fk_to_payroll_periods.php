<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the foreign-key constraint on payroll_periods.journal_entry_id ->
 * journal_entries.id that couldn't be declared at create-time because the
 * journal_entries table is created later in the sprint (migration 0039).
 *
 * On journal_entry deletion: SET NULL — preserves the period record but
 * forces a re-post via the GL service if anyone ever wipes a JE.
 *
 * This migration is idempotent: it skips when the FK already exists (e.g. if
 * Sprint 4 ever rebuilds the JE table).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_periods') || ! Schema::hasTable('journal_entries')) {
            return;
        }
        if (! Schema::hasColumn('payroll_periods', 'journal_entry_id')) {
            return;
        }

        // Detect existing FK to avoid a duplicate-constraint failure on rerun.
        // Laravel 11 ships native Schema::getForeignKeys (no doctrine dep).
        $existing = collect(Schema::getForeignKeys('payroll_periods'))
            ->first(fn ($fk) => in_array('journal_entry_id', (array) ($fk['columns'] ?? []), true));
        if ($existing) {
            return;
        }

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->foreign('journal_entry_id')
                ->references('id')->on('journal_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_periods')) return;

        Schema::table('payroll_periods', function (Blueprint $table) {
            // Drop without erroring if it doesn't exist (Doctrine listing is
            // expensive in down(); rely on Laravel's safe drop helper).
            try {
                $table->dropForeign(['journal_entry_id']);
            } catch (\Throwable) {
                // ignore
            }
        });
    }
};
