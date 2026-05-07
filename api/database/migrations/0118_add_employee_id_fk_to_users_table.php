<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U1 — Promote `users.employee_id` to a uniquely-indexed FK so the link
 * between an employee and their system account is enforced 1:1.
 *
 * Note on prior history:
 *   - 0004 created `users.employee_id` as a plain `unsignedBigInteger` + index.
 *   - 0017 already adds the FK constraint inside a try/catch (because some
 *     Sprint 1 environments had the column unconstrained, others didn't).
 *
 * This migration's only *new* contribution is therefore the **unique** index
 * (1:1 employee↔user). The FK addition is wrapped the same way as 0017 so
 * the migration is idempotent across Postgres and SQLite, and so a fresh
 * migrate from scratch — where 0017 will already have created the FK before
 * 0118 runs — does not crash with "constraint already exists".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            try {
                $table->unique('employee_id', 'users_employee_id_unique');
            } catch (\Throwable $e) {
                // Already unique — fine.
            }
        });

        Schema::table('users', function (Blueprint $table) {
            try {
                $table->foreign('employee_id', 'users_employee_id_foreign')
                    ->references('id')->on('employees')
                    ->nullOnDelete();
            } catch (\Throwable $e) {
                // FK already added by 0017 — fine.
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            try { $table->dropForeign('users_employee_id_foreign'); } catch (\Throwable $e) {}
            try { $table->dropUnique('users_employee_id_unique'); } catch (\Throwable $e) {}
        });
    }
};
