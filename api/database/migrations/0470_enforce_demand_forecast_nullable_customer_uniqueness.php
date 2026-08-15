<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-021 — one forecast per product/customer scope and period.
 *
 * PostgreSQL's historical UNIQUE semantics treat NULL customer_id values as
 * distinct, allowing duplicate total (all-customer) forecasts. PostgreSQL 16
 * supports NULLS NOT DISTINCT, which makes NULL customer_id participate in the
 * logical key without changing the forecast policy or customer-specific rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Fail before changing indexes. Existing business rows are never
        // deleted or silently deduplicated by this migration.
        $duplicates = DB::select(
            'SELECT product_id, customer_id, forecast_year, forecast_month, COUNT(*) AS duplicate_count
               FROM demand_forecasts
              GROUP BY product_id, customer_id, forecast_year, forecast_month
             HAVING COUNT(*) > 1
              ORDER BY product_id, customer_id NULLS FIRST, forecast_year, forecast_month'
        );

        if ($duplicates !== []) {
            $details = collect($duplicates)
                ->map(static fn (object $row): string => sprintf(
                    'product %d/customer %s/%04d-%02d (%d rows)',
                    $row->product_id,
                    $row->customer_id === null ? 'total' : (string) $row->customer_id,
                    $row->forecast_year,
                    $row->forecast_month,
                    $row->duplicate_count,
                ))
                ->implode(', ');

            throw new RuntimeException(
                'Cannot enforce demand forecast uniqueness: duplicate logical rows exist for '.$details.'. Resolve the business records before retrying; this migration does not delete or deduplicate rows.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX demand_forecasts_scope_nulls_not_distinct_unique
               ON demand_forecasts (product_id, customer_id, forecast_year, forecast_month)
               NULLS NOT DISTINCT'
        );

        // The legacy unique was created through Blueprint::unique(), so on
        // PostgreSQL it is owned by a table constraint rather than being a
        // standalone droppable index.
        DB::statement('ALTER TABLE demand_forecasts DROP CONSTRAINT IF EXISTS demand_forecasts_scope_unique');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS demand_forecasts_scope_nulls_not_distinct_unique');
        DB::statement(
            'ALTER TABLE demand_forecasts
              ADD CONSTRAINT demand_forecasts_scope_unique
              UNIQUE (product_id, customer_id, forecast_year, forecast_month)'
        );
    }
};
