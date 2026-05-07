<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U1 — Promote `users.employee_id` to a uniquely-indexed FK so the link
 * between an employee and their system account is enforced 1:1.
 *
 * Note on prior history:
 *   - 0004 created `users.employee_id` as a plain `unsignedBigInteger` + index.
 *   - 0017 already adds the FK constraint inside its own try/catch block.
 *
 * This migration's only *new* contribution is therefore the **unique** index.
 * We must not naively re-add the FK — Postgres rejects `ALTER TABLE ... ADD
 * CONSTRAINT` when the constraint name already exists. We use Postgres'
 * information_schema (and a SQLite no-op) to check before adding, so the
 * migration is fully idempotent on both engines.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // 1. Add the unique constraint (the new contribution from this migration).
        if (! $this->indexExists('users', 'users_employee_id_unique', $driver)) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('employee_id', 'users_employee_id_unique');
            });
        }

        // 2. Add the FK only if it isn't already there (0017 may have created it).
        if (! $this->foreignKeyExists('users', 'users_employee_id_foreign', $driver)) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('employee_id', 'users_employee_id_foreign')
                    ->references('id')->on('employees')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($this->foreignKeyExists('users', 'users_employee_id_foreign', $driver)) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign('users_employee_id_foreign');
            });
        }
        if ($this->indexExists('users', 'users_employee_id_unique', $driver)) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_employee_id_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName, string $driver): bool
    {
        if ($driver === 'pgsql') {
            return (bool) DB::selectOne(
                'SELECT 1 AS hit FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $indexName],
            );
        }
        if ($driver === 'sqlite') {
            return (bool) DB::selectOne(
                "SELECT 1 AS hit FROM sqlite_master WHERE type='index' AND name = ?",
                [$indexName],
            );
        }
        // mysql: SHOW INDEX FROM `table` WHERE Key_name = ?
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return ! empty($rows);
    }

    private function foreignKeyExists(string $table, string $constraintName, string $driver): bool
    {
        if ($driver === 'pgsql') {
            return (bool) DB::selectOne(
                "SELECT 1 AS hit FROM pg_constraint c
                 JOIN pg_class t ON t.oid = c.conrelid
                 WHERE t.relname = ? AND c.conname = ? AND c.contype = 'f'",
                [$table, $constraintName],
            );
        }
        if ($driver === 'sqlite') {
            // SQLite tracks FKs internally; we check via PRAGMA foreign_key_list.
            // Constraint names are not directly exposed; treat absence as "FK already
            // declared via 0004→0017"; safe to skip re-adding because SQLite doesn't
            // enforce duplicate-name conflicts here.
            $rows = DB::select("PRAGMA foreign_key_list('{$table}')");
            return ! empty($rows);
        }
        // mysql:
        $rows = DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraintName],
        );
        return ! empty($rows);
    }
};
