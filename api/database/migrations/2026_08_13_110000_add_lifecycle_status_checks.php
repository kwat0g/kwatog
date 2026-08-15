<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-033 — keep enum-backed lifecycle roots closed at the database boundary.
 *
 * This is deliberately a bounded tranche.  The values below are the complete
 * values of the corresponding PHP enums at this migration's release.  Before
 * adding a guard, existing values are reported and the migration fails without
 * changing or deleting business rows.
 *
 * PostgreSQL supports adding a table CHECK to an existing table.  SQLite does
 * not support ALTER TABLE ... ADD CONSTRAINT, so its equivalent additive guard
 * is two aborting triggers (insert/update).  Both drivers therefore reject the
 * same invalid status values without rebuilding a live table.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> */
    private const STATUSES = [
        'journal_entries' => [
            'status' => ['draft', 'posted', 'reversed'],
        ],
        'stock_adjustments' => [
            'status' => ['pending', 'approved'],
        ],
        'stock_count_sessions' => [
            'status' => ['draft', 'in_progress', 'completed', 'cancelled'],
        ],
        'stock_count_items' => [
            'status' => ['pending', 'counted', 'verified', 'adjusted'],
        ],
        'inspections' => [
            'status' => ['draft', 'in_progress', 'passed', 'failed', 'cancelled'],
        ],
        'deliveries' => [
            'status' => ['scheduled', 'loading', 'in_transit', 'delivered', 'confirmed', 'cancelled'],
        ],
        'payroll_periods' => [
            'status' => ['draft', 'processing', 'computed', 'approved', 'finalized', 'disbursed', 'voided'],
            'bank_file_status' => ['not_started', 'pending', 'manual_required', 'generated'],
            'gl_handoff_status' => ['not_started', 'pending', 'manual_required', 'posted', 'not_required'],
        ],
    ];

    /** @var array<string, string> */
    private const CONSTRAINTS = [
        'journal_entries.status' => 'journal_entries_status_check',
        'stock_adjustments.status' => 'stock_adjustments_status_check',
        'stock_count_sessions.status' => 'stock_count_sessions_status_check',
        'stock_count_items.status' => 'stock_count_items_status_check',
        'inspections.status' => 'inspections_status_check',
        'deliveries.status' => 'deliveries_status_check',
        'payroll_periods.status' => 'payroll_periods_status_check',
        'payroll_periods.bank_file_status' => 'payroll_periods_bank_file_status_check',
        'payroll_periods.gl_handoff_status' => 'payroll_periods_gl_handoff_status_check',
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(
                "Lifecycle status constraints require PostgreSQL or SQLite; received {$driver}."
            );
        }

        foreach (self::STATUSES as $table => $columns) {
            foreach ($columns as $column => $allowed) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $key = $table.'.'.$column;
                $name = self::CONSTRAINTS[$key];
                if ($this->guardExists($driver, $table, $name)) {
                    continue;
                }

                $invalid = DB::table($table)
                    ->select($column, DB::raw('COUNT(*) AS row_count'))
                    ->whereNotIn($column, $allowed)
                    ->groupBy($column)
                    ->orderBy($column)
                    ->get();

                if ($invalid->isNotEmpty()) {
                    $details = $invalid
                        ->map(static fn (object $row): string => sprintf(
                            '%s (%d rows)',
                            $row->{$column} === null ? 'NULL' : "'{$row->{$column}}'",
                            (int) $row->row_count,
                        ))
                        ->implode(', ');

                    throw new RuntimeException(
                        "Cannot add {$name}: {$table}.{$column} contains unsupported values {$details}. Resolve the records before retrying; this migration never deletes or rewrites them."
                    );
                }

                if ($driver === 'pgsql') {
                    DB::statement(
                        'ALTER TABLE '.$this->quotePgIdentifier($table)
                        .' ADD CONSTRAINT '.$this->quotePgIdentifier($name)
                        .' CHECK ('.$this->quotePgIdentifier($column).' IN ('.$this->sqlValues($allowed).'))'
                    );
                } else {
                    // SQLite cannot append a table CHECK with ALTER TABLE.
                    // These named triggers are the SQLite-compatible
                    // equivalent and are dropped by down() without touching
                    // existing rows.
                    DB::statement(
                        'CREATE TRIGGER '.$name.'_insert_guard '
                        .'BEFORE INSERT ON '.$this->quoteSqliteIdentifier($table).' '
                        .'WHEN NEW.'.$this->quoteSqliteIdentifier($column).' IS NOT NULL AND NEW.'.$this->quoteSqliteIdentifier($column).' NOT IN ('.$this->sqlValues($allowed).') '
                        ."BEGIN SELECT RAISE(ABORT, 'invalid {$table}.{$column}'); END"
                    );
                    DB::statement(
                        'CREATE TRIGGER '.$name.'_update_guard '
                        .'BEFORE UPDATE OF '.$this->quoteSqliteIdentifier($column).' ON '.$this->quoteSqliteIdentifier($table).' '
                        .'WHEN NEW.'.$this->quoteSqliteIdentifier($column).' IS NOT NULL AND NEW.'.$this->quoteSqliteIdentifier($column).' NOT IN ('.$this->sqlValues($allowed).') '
                        ."BEGIN SELECT RAISE(ABORT, 'invalid {$table}.{$column}'); END"
                    );
                }
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            foreach (self::CONSTRAINTS as $name) {
                DB::statement('ALTER TABLE '.$this->quotePgIdentifier($this->tableForConstraint($name)).' DROP CONSTRAINT IF EXISTS '.$this->quotePgIdentifier($name));
            }
        } elseif ($driver === 'sqlite') {
            foreach (self::CONSTRAINTS as $name) {
                DB::statement('DROP TRIGGER IF EXISTS '.$name.'_insert_guard');
                DB::statement('DROP TRIGGER IF EXISTS '.$name.'_update_guard');
            }
        }
    }

    private function guardExists(string $driver, string $table, string $name): bool
    {
        if ($driver === 'pgsql') {
            return DB::table('pg_constraint as c')
                ->join('pg_class as r', 'r.oid', '=', 'c.conrelid')
                ->where('r.relname', $table)
                ->where('c.conname', $name)
                ->exists();
        }

        return DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', [$name.'_insert_guard', $name.'_update_guard'])
            ->count() === 2;
    }

    /** @param list<string> $values */
    private function sqlValues(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quotePgIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function tableForConstraint(string $constraint): string
    {
        $key = array_search($constraint, self::CONSTRAINTS, true);
        if (! is_string($key)) {
            throw new RuntimeException("Unknown lifecycle status constraint {$constraint}.");
        }

        return explode('.', $key, 2)[0];
    }
};
