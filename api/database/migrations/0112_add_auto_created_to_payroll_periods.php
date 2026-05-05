<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task A3 — Auto Payroll Period creation flag. Marks periods that were
 * auto-created by the scheduler so the UI can show an "Auto" chip.
 *
 * Also makes payroll_periods.created_by nullable so the system scheduler
 * can author rows when no human user is available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->boolean('is_auto_created')->default(false)->after('status');
            $table->timestamp('auto_created_at')->nullable()->after('is_auto_created');
        });

        // Make created_by nullable. PostgreSQL syntax — primary deployment target.
        $driver = Schema::getConnection()->getDriverName();
        try {
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE payroll_periods ALTER COLUMN created_by DROP NOT NULL');
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE payroll_periods MODIFY created_by BIGINT UNSIGNED NULL');
            }
        } catch (\Throwable $e) {
            // Best-effort; column may already be nullable on rerun.
        }
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropColumn(['is_auto_created', 'auto_created_at']);
        });
    }
};
