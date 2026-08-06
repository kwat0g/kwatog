<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database-level guard: one employee may be paid at most once per pay cycle.
 *
 * Scoped periods (migration 0438) deliberately allow several periods to cover
 * the same dates, so the old "reject any overlapping period" rule had to go.
 * That removed the only thing preventing an employee from landing in two runs
 * for the same cutoff — pay them under "Regular · all departments" and again
 * under "Production department", and they are paid twice with two sets of
 * government contributions and two loan amortizations taken.
 *
 * Application-level checks alone cannot close this: two Compute jobs for
 * different periods run concurrently in separate workers and separate
 * transactions, so both can pass a "does a payroll row exist elsewhere?" read
 * before either writes. Only a unique index is race-proof.
 *
 * cycle_key is the pay cycle a payroll row belongs to, derived from its
 * period's year + month + half — the same string
 * PayrollPeriod::cycleKey() builds, which is the only definition:
 *
 *   2026-04-H1   first half of April 2026
 *   2026-04-H2   second half
 *   2026-13TH    that year's 13th-month run
 *
 * Keyed on year+month rather than exact dates so two periods covering one
 * cutoff with slightly different windows still collide as they should.
 *
 * The row is deleted with its payroll (cascade), so a recompute — which drops
 * and rewrites payroll rows — releases and re-takes the claim inside the same
 * transaction. Voiding a period releases its claims explicitly so a replacement
 * run can pay those employees (see PayrollPeriodService::void).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_cycle_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->string('cycle_key', 20);
            $table->timestamps();

            // THE guard. A second period trying to pay the same employee in the
            // same cycle fails here regardless of which worker gets there first.
            $table->unique(['employee_id', 'cycle_key']);
            $table->unique('payroll_id');
            $table->index(['payroll_period_id', 'cycle_key']);
        });

        // Backfill claims for payroll that already exists, so the guard is
        // authoritative from the moment it ships rather than only for new runs.
        // Voided periods are skipped — their rows are historical, and holding a
        // claim for them would block the replacement run they exist to enable.
        //
        // Duplicates predating this index (if any) would break the unique, so
        // we keep only the earliest payroll row per (employee, cycle) and log
        // nothing silently: the DISTINCT ON leaves later duplicates unclaimed,
        // which surfaces them in the payroll-integrity report rather than
        // failing the deploy.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("
                INSERT INTO payroll_cycle_claims
                    (employee_id, payroll_id, payroll_period_id, cycle_key, created_at, updated_at)
                SELECT DISTINCT ON (p.employee_id, ck.cycle_key)
                    p.employee_id,
                    p.id,
                    p.payroll_period_id,
                    ck.cycle_key,
                    NOW(),
                    NOW()
                FROM payrolls p
                JOIN payroll_periods pp ON pp.id = p.payroll_period_id
                CROSS JOIN LATERAL (
                    SELECT CASE
                        WHEN pp.is_thirteenth_month THEN TO_CHAR(pp.period_start, 'YYYY') || '-13TH'
                        WHEN pp.is_first_half       THEN TO_CHAR(pp.period_start, 'YYYY-MM') || '-H1'
                        ELSE                             TO_CHAR(pp.period_start, 'YYYY-MM') || '-H2'
                    END AS cycle_key
                ) ck
                WHERE pp.status <> 'voided'
                ORDER BY p.employee_id, ck.cycle_key, p.id
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_cycle_claims');
    }
};
