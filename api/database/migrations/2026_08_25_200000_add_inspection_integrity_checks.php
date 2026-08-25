<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep Quality inspection invariants closed at the database boundary.
 *
 * PostgreSQL uses named CHECK constraints. SQLite uses equivalent insert/update
 * triggers because SQLite cannot append a table CHECK with ALTER TABLE.
 * Existing invalid rows abort the migration with a count; this migration never
 * rewrites inspection evidence.
 */
return new class extends Migration
{
    private const STAGES = ['incoming', 'in_process', 'outgoing', 'supplier_return', 'customer_return'];

    private const ENTITY_TYPES = ['grn', 'work_order', 'delivery', 'return_request'];

    private const PARAMETER_TYPES = ['dimensional', 'visual', 'functional'];

    /** @var array<string, string> */
    private const TABLES = [
        'inspections_stage_check' => 'inspections',
        'inspections_entity_type_check' => 'inspections',
        'inspections_batch_quantity_check' => 'inspections',
        'inspections_sample_size_check' => 'inspections',
        'inspections_spec_revision_pair_check' => 'inspections',
        'inspection_spec_items_parameter_type_check' => 'inspection_spec_items',
        'inspection_spec_items_tolerance_order_check' => 'inspection_spec_items',
        'inspection_spec_items_nominal_min_check' => 'inspection_spec_items',
        'inspection_spec_items_nominal_max_check' => 'inspection_spec_items',
        'inspection_spec_items_type_contract_check' => 'inspection_spec_items',
        'inspection_measurements_parameter_type_check' => 'inspection_measurements',
        'inspection_measurements_sample_index_check' => 'inspection_measurements',
        'inspection_measurements_tolerance_order_check' => 'inspection_measurements',
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(
                "Inspection integrity checks require PostgreSQL or SQLite; received {$driver}."
            );
        }

        $this->addCheck(
            'inspections',
            'inspections_stage_check',
            'stage IN ('.$this->sqlValues(self::STAGES).')',
            static fn (Builder $query): Builder => $query
                ->whereNull('stage')
                ->orWhereNotIn('stage', self::STAGES),
        );
        $this->addCheck(
            'inspections',
            'inspections_entity_type_check',
            'entity_type IS NULL OR entity_type IN ('.$this->sqlValues(self::ENTITY_TYPES).')',
            static fn (Builder $query): Builder => $query
                ->whereNotNull('entity_type')
                ->whereNotIn('entity_type', self::ENTITY_TYPES),
        );
        $this->addCheck(
            'inspections',
            'inspections_batch_quantity_check',
            'batch_quantity > 0',
            static fn (Builder $query): Builder => $query
                ->whereNull('batch_quantity')
                ->orWhere('batch_quantity', '<=', 0),
        );
        $this->addCheck(
            'inspections',
            'inspections_sample_size_check',
            'sample_size > 0 AND sample_size <= batch_quantity',
            static fn (Builder $query): Builder => $query
                ->whereNull('sample_size')
                ->orWhere('sample_size', '<=', 0)
                ->orWhereColumn('sample_size', '>', 'batch_quantity'),
        );
        $this->addCheck(
            'inspections',
            'inspections_spec_revision_pair_check',
            '(inspection_spec_id IS NULL AND inspection_spec_revision_id IS NULL) OR (inspection_spec_id IS NOT NULL AND inspection_spec_revision_id IS NOT NULL)',
            static fn (Builder $query): Builder => $query
                ->where(function (Builder $pair): void {
                    $pair->whereNull('inspection_spec_id')->whereNotNull('inspection_spec_revision_id')
                        ->orWhere(function (Builder $reverse): void {
                            $reverse->whereNotNull('inspection_spec_id')->whereNull('inspection_spec_revision_id');
                        });
                }),
        );
        $this->addCheck(
            'inspection_spec_items',
            'inspection_spec_items_parameter_type_check',
            'parameter_type IN ('.$this->sqlValues(self::PARAMETER_TYPES).')',
            static fn (Builder $query): Builder => $query
                ->whereNull('parameter_type')
                ->orWhereNotIn('parameter_type', self::PARAMETER_TYPES),
        );
        $this->addCheck(
            'inspection_spec_items',
            'inspection_spec_items_tolerance_order_check',
            'tolerance_min IS NULL OR tolerance_max IS NULL OR tolerance_min <= tolerance_max',
            static fn (Builder $query): Builder => $query
                ->whereNotNull('tolerance_min')
                ->whereNotNull('tolerance_max')
                ->whereColumn('tolerance_min', '>', 'tolerance_max'),
        );
        $this->addCheck(
            'inspection_spec_items',
            'inspection_spec_items_nominal_min_check',
            'nominal_value IS NULL OR tolerance_min IS NULL OR nominal_value >= tolerance_min',
            static fn (Builder $query): Builder => $query
                ->whereNotNull('nominal_value')
                ->whereNotNull('tolerance_min')
                ->whereColumn('nominal_value', '<', 'tolerance_min'),
        );
        $this->addCheck(
            'inspection_spec_items',
            'inspection_spec_items_nominal_max_check',
            'nominal_value IS NULL OR tolerance_max IS NULL OR nominal_value <= tolerance_max',
            static fn (Builder $query): Builder => $query
                ->whereNotNull('nominal_value')
                ->whereNotNull('tolerance_max')
                ->whereColumn('nominal_value', '>', 'tolerance_max'),
        );
        $this->addCheck(
            'inspection_spec_items',
            'inspection_spec_items_type_contract_check',
            "parameter_type = 'dimensional' OR (parameter_type = 'visual' AND nominal_value IS NULL AND tolerance_min IS NULL AND tolerance_max IS NULL) OR (parameter_type = 'functional' AND NOT (nominal_value IS NOT NULL AND tolerance_min IS NULL AND tolerance_max IS NULL))",
            static fn (Builder $query): Builder => $query->where(function (Builder $invalid): void {
                // Dimensional rows may be legacy direct-writer records without
                // a nominal; the request boundary requires the complete
                // dimensional contract for new HTTP writes.
                $invalid->where(function (Builder $visual): void {
                    $visual->where('parameter_type', 'visual')
                        ->where(function (Builder $numeric): void {
                            $numeric->whereNotNull('nominal_value')
                                ->orWhereNotNull('tolerance_min')
                                ->orWhereNotNull('tolerance_max');
                        });
                })->orWhere(function (Builder $functional): void {
                    $functional->where('parameter_type', 'functional')
                        ->whereNotNull('nominal_value')
                        ->whereNull('tolerance_min')
                        ->whereNull('tolerance_max');
                });
            }),
        );
        $this->addCheck(
            'inspection_measurements',
            'inspection_measurements_parameter_type_check',
            'parameter_type IN ('.$this->sqlValues(self::PARAMETER_TYPES).')',
            static fn (Builder $query): Builder => $query
                ->whereNull('parameter_type')
                ->orWhereNotIn('parameter_type', self::PARAMETER_TYPES),
        );
        $this->addCheck(
            'inspection_measurements',
            'inspection_measurements_sample_index_check',
            'sample_index > 0',
            static fn (Builder $query): Builder => $query
                ->whereNull('sample_index')
                ->orWhere('sample_index', '<=', 0),
        );
        $this->addCheck(
            'inspection_measurements',
            'inspection_measurements_tolerance_order_check',
            'tolerance_min IS NULL OR tolerance_max IS NULL OR tolerance_min <= tolerance_max',
            static fn (Builder $query): Builder => $query
                ->whereNotNull('tolerance_min')
                ->whereNotNull('tolerance_max')
                ->whereColumn('tolerance_min', '>', 'tolerance_max'),
        );

        $this->assertRevisionLineageMatches();
        $this->addCompositeLineageForeignKeys();
    }

    public function down(): void
    {
        $this->dropCompositeLineageForeignKeys();

        $driver = DB::getDriverName();
        foreach (self::TABLES as $name => $table) {
            if ($driver === 'pgsql') {
                DB::statement(
                    'ALTER TABLE '.$this->quote($table)
                    .' DROP CONSTRAINT IF EXISTS '.$this->quote($name)
                );
            } elseif ($driver === 'sqlite') {
                DB::statement('DROP TRIGGER IF EXISTS '.$this->quote($name.'_insert_guard'));
                DB::statement('DROP TRIGGER IF EXISTS '.$this->quote($name.'_update_guard'));
            }
        }
    }

    private function assertRevisionLineageMatches(): void
    {
        if (! Schema::hasTable('inspection_spec_revisions')) {
            return;
        }

        $itemMismatch = DB::table('inspection_spec_items as item')
            ->leftJoin('inspection_spec_revisions as revision', 'revision.id', '=', 'item.inspection_spec_revision_id')
            ->whereNotNull('item.inspection_spec_revision_id')
            ->where(function (Builder $query): void {
                $query->whereNull('revision.id')
                    ->orWhereColumn('item.inspection_spec_id', '<>', 'revision.inspection_spec_id');
            })
            ->count();

        $inspectionMismatch = DB::table('inspections as inspection')
            ->leftJoin('inspection_spec_revisions as revision', 'revision.id', '=', 'inspection.inspection_spec_revision_id')
            ->whereNotNull('inspection.inspection_spec_revision_id')
            ->where(function (Builder $query): void {
                $query->whereNull('revision.id')
                    ->orWhereColumn('inspection.inspection_spec_id', '<>', 'revision.inspection_spec_id');
            })
            ->count();

        if ($itemMismatch > 0 || $inspectionMismatch > 0) {
            throw new RuntimeException(
                "Cannot add inspection revision lineage constraints: {$itemMismatch} item row(s) and {$inspectionMismatch} inspection row(s) pair a spec with a different or missing revision. Resolve the records before retrying; this migration never rewrites quality evidence."
            );
        }
    }

    private function addCompositeLineageForeignKeys(): void
    {
        if (! Schema::hasTable('inspection_spec_revisions')) {
            return;
        }

        if (! Schema::hasIndex('inspection_spec_revisions', 'inspection_spec_revisions_id_spec_unique')) {
            Schema::table('inspection_spec_revisions', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->unique(
                    ['id', 'inspection_spec_id'],
                    'inspection_spec_revisions_id_spec_unique',
                );
            });
        }

        if (Schema::hasTable('inspection_spec_items')
            && Schema::hasColumn('inspection_spec_items', 'inspection_spec_revision_id')) {
            Schema::table('inspection_spec_items', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->dropForeign(['inspection_spec_revision_id']);
                $table->foreign(
                    ['inspection_spec_revision_id', 'inspection_spec_id'],
                    'inspection_spec_items_revision_spec_foreign',
                )->references(['id', 'inspection_spec_id'])
                    ->on('inspection_spec_revisions')
                    ->restrictOnDelete();
            });
        }

        if (Schema::hasTable('inspections')
            && Schema::hasColumn('inspections', 'inspection_spec_revision_id')) {
            Schema::table('inspections', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->dropForeign(['inspection_spec_revision_id']);
                $table->foreign(
                    ['inspection_spec_revision_id', 'inspection_spec_id'],
                    'inspections_revision_spec_foreign',
                )->references(['id', 'inspection_spec_id'])
                    ->on('inspection_spec_revisions')
                    ->restrictOnDelete();
            });
        }
    }

    private function dropCompositeLineageForeignKeys(): void
    {
        if (Schema::hasTable('inspection_spec_items')
            && Schema::hasColumn('inspection_spec_items', 'inspection_spec_revision_id')) {
            Schema::table('inspection_spec_items', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->dropForeign('inspection_spec_items_revision_spec_foreign');
                $table->foreign('inspection_spec_revision_id')
                    ->references('id')
                    ->on('inspection_spec_revisions')
                    ->restrictOnDelete();
            });
        }

        if (Schema::hasTable('inspections')
            && Schema::hasColumn('inspections', 'inspection_spec_revision_id')) {
            Schema::table('inspections', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->dropForeign('inspections_revision_spec_foreign');
                $table->foreign('inspection_spec_revision_id')
                    ->references('id')
                    ->on('inspection_spec_revisions')
                    ->restrictOnDelete();
            });
        }

        if (Schema::hasTable('inspection_spec_revisions')
            && Schema::hasIndex('inspection_spec_revisions', 'inspection_spec_revisions_id_spec_unique')) {
            Schema::table('inspection_spec_revisions', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->dropUnique('inspection_spec_revisions_id_spec_unique');
            });
        }
    }

    /** @param callable(Builder): Builder $invalidQuery */
    private function addCheck(
        string $table,
        string $name,
        string $condition,
        callable $invalidQuery,
    ): void {
        if (! Schema::hasTable($table) || $this->guardExists($table, $name)) {
            return;
        }

        $invalidCount = $invalidQuery(DB::table($table))->count();
        if ($invalidCount > 0) {
            throw new RuntimeException(
                "Cannot add {$name}: {$table} contains {$invalidCount} rows that violate the inspection invariant. Resolve the records before retrying; this migration never rewrites them."
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE '.$this->quote($table)
                .' ADD CONSTRAINT '.$this->quote($name)
                .' CHECK ('.$condition.')'
            );

            return;
        }

        DB::statement(
            'CREATE TRIGGER '.$this->quote($name.'_insert_guard')
            .' BEFORE INSERT ON '.$this->quote($table)
            .' WHEN NOT ('.$this->prefixNewValues($condition).')'
            ." BEGIN SELECT RAISE(ABORT, 'invalid {$table} inspection invariant'); END"
        );
        DB::statement(
            'CREATE TRIGGER '.$this->quote($name.'_update_guard')
            .' BEFORE UPDATE ON '.$this->quote($table)
            .' WHEN NOT ('.$this->prefixNewValues($condition).')'
            ." BEGIN SELECT RAISE(ABORT, 'invalid {$table} inspection invariant'); END"
        );
    }

    private function guardExists(string $table, string $name): bool
    {
        if (DB::getDriverName() === 'pgsql') {
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

    private function prefixNewValues(string $condition): string
    {
        foreach ([
            'stage', 'entity_type', 'batch_quantity', 'sample_size',
            'inspection_spec_id', 'inspection_spec_revision_id',
            'parameter_type', 'nominal_value', 'sample_index',
            'tolerance_min', 'tolerance_max',
        ] as $column) {
            $condition = preg_replace('/(?<![A-Za-z0-9_])'.preg_quote($column, '/').'\b/', 'NEW.'.$column, $condition) ?? $condition;
        }

        return $condition;
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
