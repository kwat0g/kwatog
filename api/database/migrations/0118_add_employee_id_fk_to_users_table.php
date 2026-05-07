<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U1 — Promote `users.employee_id` from a bare unsigned bigint to a real FK
 * with a unique index. The column was created in 0004 but without constraint;
 * `Employee::user()` already does `hasOne(User, 'employee_id')` so the link
 * is bidirectional once this constraint is in place. Unique index enforces
 * 1:1 employee↔user.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('employee_id', 'users_employee_id_unique');
        });

        // Add FK constraint separately so it won't fail if the column already
        // had data referencing missing employees (it should not, but be safe).
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_id', 'users_employee_id_foreign')
                ->references('id')->on('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_employee_id_foreign');
            $table->dropUnique('users_employee_id_unique');
        });
    }
};
