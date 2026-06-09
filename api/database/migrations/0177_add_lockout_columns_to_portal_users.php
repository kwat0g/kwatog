<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-4 — B2B portal auth rebuild.
 *
 * Adds the same lockout + audit columns to both portal user tables that the
 * internal `users` table already carries. The B2bAuthService mirrors the
 * AuthService 5-strikes / 15-min lockout policy off these columns.
 *
 * Defensive guards via Schema::hasColumn so the migration is idempotent on
 * partial re-runs (matches the 0174/0175 style).
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'supplier_portal_users',
        'customer_portal_users',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'failed_login_attempts')) {
                    $t->unsignedInteger('failed_login_attempts')->default(0)->after('is_active');
                }
                if (! Schema::hasColumn($table, 'locked_until')) {
                    $t->timestamp('locked_until')->nullable()->after('failed_login_attempts');
                }
                if (! Schema::hasColumn($table, 'password_changed_at')) {
                    $t->timestamp('password_changed_at')->nullable()->after('locked_until');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['password_changed_at', 'locked_until', 'failed_login_attempts'] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
