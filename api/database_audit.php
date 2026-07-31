<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$problems = [];

$unvalidatedConstraints = DB::select(<<<'SQL'
    SELECT n.nspname AS schema_name, c.conrelid::regclass::text AS table_name, c.conname
    FROM pg_constraint c
    JOIN pg_namespace n ON n.oid = c.connamespace
    WHERE n.nspname = current_schema() AND NOT c.convalidated
    ORDER BY c.conrelid::regclass::text, c.conname
    SQL);
foreach ($unvalidatedConstraints as $constraint) {
    $problems[] = "UNVALIDATED_CONSTRAINT {$constraint->table_name}.{$constraint->conname}";
}

$invalidIndexes = DB::select(<<<'SQL'
    SELECT i.indrelid::regclass::text AS table_name, ci.relname AS index_name,
           i.indisvalid, i.indisready
    FROM pg_index i
    JOIN pg_class ci ON ci.oid = i.indexrelid
    JOIN pg_namespace n ON n.oid = ci.relnamespace
    WHERE n.nspname = current_schema() AND (NOT i.indisvalid OR NOT i.indisready)
    ORDER BY i.indrelid::regclass::text, ci.relname
    SQL);
foreach ($invalidIndexes as $index) {
    $problems[] = "INVALID_INDEX {$index->table_name}.{$index->index_name}";
}

$foreignKeys = DB::select(<<<'SQL'
    SELECT c.conname,
           c.conrelid::regclass::text AS child_table,
           c.confrelid::regclass::text AS parent_table,
           string_agg(format('child.%I = parent.%I', child_col.attname, parent_col.attname), ' AND ' ORDER BY keys.ordinality) AS join_sql,
           string_agg(format('child.%I IS NOT NULL', child_col.attname), ' AND ' ORDER BY keys.ordinality) AS child_present_sql,
           string_agg(format('parent.%I IS NULL', parent_col.attname), ' AND ' ORDER BY keys.ordinality) AS parent_missing_sql
    FROM pg_constraint c
    JOIN pg_namespace n ON n.oid = c.connamespace
    CROSS JOIN LATERAL unnest(c.conkey, c.confkey) WITH ORDINALITY AS keys(child_attnum, parent_attnum, ordinality)
    JOIN pg_attribute child_col ON child_col.attrelid = c.conrelid AND child_col.attnum = keys.child_attnum
    JOIN pg_attribute parent_col ON parent_col.attrelid = c.confrelid AND parent_col.attnum = keys.parent_attnum
    WHERE c.contype = 'f' AND n.nspname = current_schema()
    GROUP BY c.conname, c.conrelid, c.confrelid
    ORDER BY c.conrelid::regclass::text, c.conname
    SQL);

foreach ($foreignKeys as $foreignKey) {
    $sql = sprintf(
        'SELECT count(*) AS aggregate FROM %s child LEFT JOIN %s parent ON %s WHERE %s AND %s',
        $foreignKey->child_table,
        $foreignKey->parent_table,
        $foreignKey->join_sql,
        $foreignKey->child_present_sql,
        $foreignKey->parent_missing_sql,
    );
    $orphanCount = (int) DB::selectOne($sql)->aggregate;
    if ($orphanCount > 0) {
        $problems[] = "ORPHANED_FOREIGN_KEY {$foreignKey->child_table}.{$foreignKey->conname}: {$orphanCount} row(s)";
    }
}

$sequences = DB::select(<<<'SQL'
    SELECT format('%I.%I', sequence_ns.nspname, sequence.relname) AS sequence_name,
           format('%I.%I', table_ns.nspname, table_rel.relname) AS table_name,
           column_attr.attname AS column_name
    FROM pg_class sequence
    JOIN pg_namespace sequence_ns ON sequence_ns.oid = sequence.relnamespace
    JOIN pg_depend dependency ON dependency.objid = sequence.oid AND dependency.deptype IN ('a', 'i')
    JOIN pg_class table_rel ON table_rel.oid = dependency.refobjid
    JOIN pg_namespace table_ns ON table_ns.oid = table_rel.relnamespace
    JOIN pg_attribute column_attr ON column_attr.attrelid = table_rel.oid AND column_attr.attnum = dependency.refobjsubid
    WHERE sequence.relkind = 'S' AND table_ns.nspname = current_schema()
    ORDER BY table_rel.relname, column_attr.attname
    SQL);

foreach ($sequences as $sequence) {
    $quotedColumn = '"'.str_replace('"', '""', $sequence->column_name).'"';
    $state = DB::selectOne(sprintf(
        'SELECT (SELECT last_value FROM %s) AS last_value, (SELECT COALESCE(max(%s), 0) FROM %s) AS max_value',
        $sequence->sequence_name,
        $quotedColumn,
        $sequence->table_name,
    ));
    if ((int) $state->last_value < (int) $state->max_value) {
        $problems[] = "SEQUENCE_BEHIND {$sequence->sequence_name}: {$state->last_value} < {$sequence->table_name}.{$sequence->column_name} {$state->max_value}";
    }
}

foreach ($problems as $problem) {
    echo $problem."\n";
}

printf(
    "\n=== %d database integrity problems across %d foreign keys and %d sequences ===\n",
    count($problems),
    count($foreignKeys),
    count($sequences),
);

exit($problems === [] ? 0 : 1);
