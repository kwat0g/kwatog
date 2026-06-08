<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-3 — Invoice draft numbering hardening.
 *
 * Drops the `DRAFT-{random hex}` placeholder pattern from `invoices.invoice_number`.
 * The real INV-YYYYMM-NNNN number is reserved at finalize-time via
 * App\Common\Services\DocumentSequenceService, so the column can stay NULL on
 * draft rows. This avoids:
 *   - DRAFT-* values polluting search/list views for cancelled drafts
 *   - tiny but non-zero collision risk on random_bytes(4)
 *   - inconsistency with every other doc number (PO/GRN/JE/...).
 *
 * Postgres allows multiple NULLs in a unique index, so the existing UNIQUE
 * constraint stays intact for finalized rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'invoice_number')) {
            return;
        }

        DB::transaction(function () {
            // Backfill: every existing DRAFT-* placeholder becomes NULL.
            DB::table('invoices')
                ->where('invoice_number', 'like', 'DRAFT-%')
                ->update(['invoice_number' => null]);

            $driver = Schema::getConnection()->getDriverName();

            try {
                if ($driver === 'pgsql') {
                    DB::statement('ALTER TABLE invoices ALTER COLUMN invoice_number DROP NOT NULL');
                } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                    DB::statement('ALTER TABLE invoices MODIFY invoice_number VARCHAR(30) NULL');
                } elseif ($driver === 'sqlite') {
                    // SQLite does not support ALTER COLUMN drop-not-null directly.
                    // The test env uses RefreshDatabase so this is a no-op there;
                    // production runs Postgres.
                }
            } catch (\Throwable $e) {
                // Best-effort; column may already be nullable on rerun.
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'invoice_number')) {
            return;
        }

        DB::transaction(function () {
            // Re-stamp NULLs with placeholders so NOT NULL can be reapplied.
            $nullRows = DB::table('invoices')
                ->whereNull('invoice_number')
                ->pluck('id');

            foreach ($nullRows as $id) {
                DB::table('invoices')
                    ->where('id', $id)
                    ->update([
                        'invoice_number' => 'DRAFT-' . substr(bin2hex(random_bytes(4)), 0, 8),
                    ]);
            }

            $driver = Schema::getConnection()->getDriverName();

            try {
                if ($driver === 'pgsql') {
                    DB::statement('ALTER TABLE invoices ALTER COLUMN invoice_number SET NOT NULL');
                } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                    DB::statement('ALTER TABLE invoices MODIFY invoice_number VARCHAR(30) NOT NULL');
                }
            } catch (\Throwable $e) {
                // Best-effort.
            }
        });
    }
};
