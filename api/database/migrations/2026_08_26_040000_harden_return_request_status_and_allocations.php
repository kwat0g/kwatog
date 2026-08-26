<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M046 RMA-005 / RMA-006 — database backstops for the RMA lifecycle and the
 * source-reservation ledger.
 *
 * `ReturnRequestStateMachine` is an explicit transition table, but
 * `return_requests.status` was an unconstrained string: migration
 * 2026_08_13_221000 guarded `inspection_handoff_status` and `quarantine_status`
 * and skipped `status` itself. A direct model or query-builder write could
 * persist a value outside `ReturnRequestStatus`, and the failure then surfaced
 * far away as an enum-hydration error, after the bad row was already committed.
 *
 * `return_request_source_allocations` (added 2026_08_25_190000) likewise had no
 * persistence-level guard: nothing stopped a negative reserved quantity, a
 * negative unit price, or a `source_kind` the service cannot resolve — and an
 * unresolvable kind throws "Unsupported return source line" from
 * `sourceLimit()` at reservation time, i.e. the row is only detected as
 * malformed when someone next tries to reserve against it.
 *
 * Application locking stays the primary control; this is the backstop.
 *
 * Named timestamp-style, not `0479_`, deliberately. Laravel orders migrations by
 * full filename, so every `04xx_` file sorts BEFORE every `2026_` file ('0' < '2').
 * `return_request_source_allocations` is created by 2026_08_25_190000, so a
 * numbered prefix would run before the table exists and this migration would
 * silently skip its allocation guards — which is exactly what happened when it
 * was first written as `0479_`. The `return_requests` guard would still have
 * applied (that table dates from 0158), making the omission invisible.
 */
return new class extends Migration
{
    /** @var array<string, array{column: string, values: list<string>}> */
    private const ENUM_GUARDS = [
        'return_requests_status_lifecycle_check' => [
            'table'  => 'return_requests',
            'column' => 'status',
            'values' => [
                'draft', 'pending_approval', 'approved', 'received',
                'inspected', 'completed', 'rejected', 'cancelled',
            ],
        ],
        'rma_source_allocations_kind_check' => [
            'table'  => 'return_request_source_allocations',
            'column' => 'source_kind',
            'values' => ['invoice_item', 'sales_order_item', 'delivery_item', 'grn_item'],
        ],
    ];

    /** @var array<string, array{table: string, column: string}> */
    private const NON_NEGATIVE_GUARDS = [
        'rma_source_allocations_quantity_check' => [
            'table'  => 'return_request_source_allocations',
            'column' => 'quantity',
        ],
        'rma_source_allocations_unit_price_check' => [
            'table'  => 'return_request_source_allocations',
            'column' => 'unit_price',
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // The rest of this sequence is PostgreSQL-only; skip rather than
            // half-apply on another driver.
            return;
        }

        foreach (self::ENUM_GUARDS as $name => $guard) {
            if (! $this->applicable($guard['table'], $guard['column'], $name)) {
                continue;
            }

            $offending = DB::table($guard['table'])
                ->whereNotNull($guard['column'])
                ->whereNotIn($guard['column'], $guard['values'])
                ->count();
            if ($offending > 0) {
                throw new RuntimeException(
                    "Cannot add {$name}: {$guard['table']}.{$guard['column']} has {$offending} row(s) "
                    .'outside the supported values; resolve them before retrying. No rows were changed.'
                );
            }

            $values = implode(', ', array_map(
                static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
                $guard['values'],
            ));
            DB::statement(
                'ALTER TABLE "'.$guard['table'].'" ADD CONSTRAINT "'.$name.'" '
                .'CHECK ("'.$guard['column'].'" IN ('.$values.') OR "'.$guard['column'].'" IS NULL)'
            );
        }

        foreach (self::NON_NEGATIVE_GUARDS as $name => $guard) {
            if (! $this->applicable($guard['table'], $guard['column'], $name)) {
                continue;
            }

            $offending = DB::table($guard['table'])->where($guard['column'], '<', 0)->count();
            if ($offending > 0) {
                throw new RuntimeException(
                    "Cannot add {$name}: {$guard['table']}.{$guard['column']} has {$offending} negative row(s); "
                    .'resolve them before retrying. No rows were changed.'
                );
            }

            DB::statement(
                'ALTER TABLE "'.$guard['table'].'" ADD CONSTRAINT "'.$name.'" '
                .'CHECK ("'.$guard['column'].'" >= 0)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_merge(array_keys(self::ENUM_GUARDS), array_keys(self::NON_NEGATIVE_GUARDS)) as $name) {
            $table = self::ENUM_GUARDS[$name]['table'] ?? self::NON_NEGATIVE_GUARDS[$name]['table'];
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT IF EXISTS "'.$name.'"');
        }
    }

    /** Table/column present and the constraint not already installed. */
    private function applicable(string $table, string $column, string $name): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return false;
        }

        return ! DB::table('pg_constraint')->where('conname', $name)->exists();
    }
};
