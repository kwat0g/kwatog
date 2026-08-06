<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scoped payroll periods.
 *
 * Until now a period always meant "every active employee". Real runs are often
 * narrower — probationary staff on a different cutoff, one department paid
 * ahead of a plant shutdown, a contractual batch settled separately. HR used to
 * do this by computing everyone and hand-deleting rows, which defeats the
 * maker-checker trail and leaves the 13th-month accrual wrong.
 *
 * Two independent filters, both nullable (null = no restriction, i.e. the
 * existing "everyone" behaviour, so every pre-existing period keeps its exact
 * meaning):
 *
 *   scope_employment_types  ["regular","probationary"]  → employees.employment_type
 *   scope_department_ids    [3, 7]                      → employees.department_id
 *   scope_pay_types         ["semi_monthly"]            → employees.pay_type
 *
 * They AND together: ["probationary"] + [3] means probationary staff in
 * department 3 only.
 *
 * Because two scoped periods may now legitimately cover the same dates,
 * PayrollPeriodService's overlap rule can no longer be a blanket date check.
 * The replacement is a coverage guard keyed on cycle_key: one employee may be
 * paid at most once per (year, month, half) — see migration 0439.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->json('scope_employment_types')->nullable()->after('is_thirteenth_month');
            $table->json('scope_department_ids')->nullable()->after('scope_employment_types');
            $table->json('scope_pay_types')->nullable()->after('scope_department_ids');
            // Human-readable summary rendered on the period header and in the
            // audit log ("Probationary · Production"). Derived on write so the
            // UI never has to re-resolve department names to describe a period.
            $table->string('scope_label', 255)->nullable()->after('scope_pay_types');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropColumn(['scope_employment_types', 'scope_department_ids', 'scope_pay_types', 'scope_label']);
        });
    }
};
