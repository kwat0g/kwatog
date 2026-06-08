<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Task 14 — widen audit_logs.action from varchar(20) to varchar(40).
 *
 * The original column couldn't hold 'login.locked_threshold' (22 chars). Auth
 * events now mirror to audit_logs (CLAUDE.md compliance + Admin UI), so we
 * keep one canonical action name across both Log::channel('auth') and the
 * audit_logs row instead of mapping the long form to a shorter slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        match (DB::connection()->getDriverName()) {
            'pgsql'  => DB::statement('ALTER TABLE audit_logs ALTER COLUMN action TYPE VARCHAR(40)'),
            'mysql'  => DB::statement('ALTER TABLE audit_logs MODIFY action VARCHAR(40) NOT NULL'),
            // sqlite has no native column-resize; keep the old length (no-op).
            default  => null,
        };
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        match (DB::connection()->getDriverName()) {
            'pgsql'  => DB::statement('ALTER TABLE audit_logs ALTER COLUMN action TYPE VARCHAR(20)'),
            'mysql'  => DB::statement('ALTER TABLE audit_logs MODIFY action VARCHAR(20) NOT NULL'),
            default  => null,
        };
    }
};
