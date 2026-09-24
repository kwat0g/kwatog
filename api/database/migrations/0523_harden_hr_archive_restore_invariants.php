<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'account_deactivated_by_archive')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->boolean('account_deactivated_by_archive')->nullable()->after('deleted_at');
            });
        }

        // Existing archived rows predate the marker. Treat an inactive linked
        // account on those rows as archive-disabled so restore repairs the
        // legacy state; future deletes record the exact cause.
        DB::statement(<<<'SQL'
            UPDATE employees AS e
            SET account_deactivated_by_archive = TRUE
            FROM users AS u
            WHERE u.employee_id = e.id
              AND e.deleted_at IS NOT NULL
              AND u.is_active = FALSE
              AND e.account_deactivated_by_archive IS NULL
        SQL);

        if (Schema::hasTable('employee_shift_assignments')) {
            DB::statement(<<<'SQL'
                DELETE FROM employee_shift_assignments older
                USING employee_shift_assignments newer
                WHERE older.employee_id = newer.employee_id
                  AND older.effective_date = newer.effective_date
                  AND older.id > newer.id
            SQL);

            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS employee_shift_assignments_employee_effective_unique '
                .'ON employee_shift_assignments (employee_id, effective_date)'
            );
        }

        if (Schema::hasTable('job_applications')) {
            DB::statement(<<<'SQL'
                DELETE FROM job_applications older
                USING job_applications newer
                WHERE older.converted_employee_id IS NOT NULL
                  AND older.converted_employee_id = newer.converted_employee_id
                  AND older.id > newer.id
            SQL);

            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS job_applications_converted_employee_unique '
                .'ON job_applications (converted_employee_id) WHERE converted_employee_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_shift_assignments')) {
            DB::statement('DROP INDEX IF EXISTS employee_shift_assignments_employee_effective_unique');
        }
        if (Schema::hasTable('job_applications')) {
            DB::statement('DROP INDEX IF EXISTS job_applications_converted_employee_unique');
        }

        if (Schema::hasColumn('employees', 'account_deactivated_by_archive')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('account_deactivated_by_archive');
            });
        }
    }
};
