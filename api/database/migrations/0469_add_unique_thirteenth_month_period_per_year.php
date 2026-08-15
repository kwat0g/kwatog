<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-019 — one live 13th-month period per calendar year.
 *
 * The application serializes computeAndPay() with a PostgreSQL transaction
 * advisory lock, but this partial unique index is the authoritative invariant
 * for writers that do not use that service. Voided historical periods remain
 * eligible for replacement runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite is the local feature-test fallback and cannot express this
        // PostgreSQL expression/predicate index. Production is PostgreSQL.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $duplicates = DB::select(
            "SELECT EXTRACT(YEAR FROM period_start)::integer AS calendar_year, COUNT(*) AS duplicate_count
               FROM payroll_periods
              WHERE is_thirteenth_month = TRUE
                AND status <> 'voided'
              GROUP BY EXTRACT(YEAR FROM period_start)::integer
             HAVING COUNT(*) > 1
              ORDER BY calendar_year"
        );

        if ($duplicates !== []) {
            $details = collect($duplicates)
                ->map(fn (object $row): string => sprintf('%d (%d rows)', $row->calendar_year, $row->duplicate_count))
                ->implode(', ');

            throw new RuntimeException(
                'Cannot add payroll_periods_thirteenth_month_year_unique: duplicate non-voided 13th-month periods exist for '.$details.'. Resolve the business records before retrying; this migration never deletes or deduplicates them.'
            );
        }

        DB::statement(
            "CREATE UNIQUE INDEX payroll_periods_thirteenth_month_year_unique
               ON payroll_periods ((EXTRACT(YEAR FROM period_start)::integer))
             WHERE is_thirteenth_month = TRUE
               AND status <> 'voided'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payroll_periods_thirteenth_month_year_unique');
        }
    }
};
