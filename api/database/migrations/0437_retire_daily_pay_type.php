<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the "daily" pay type with "semi_monthly".
 *
 * Daily-rated payroll was structurally broken against the PH semi-monthly
 * convention this system implements. Government contributions are withheld on
 * the FIRST half only and are computed from a *monthly* basis (daily_rate ×
 * work_days_per_month), while a daily employee's basic pay was days_worked ×
 * daily_rate. Any absence therefore took a full month of
 * SSS/PhilHealth/Pag-IBIG/BIR out of a partial month of pay — net clamped to
 * zero, and PayrollAnomalyService raised zero_pay + high_deduction on the row,
 * which blocks finalize(). The two halves of the calculation disagreed about
 * what a "month" was.
 *
 * Philippine practice for a company on a semi-monthly cutoff is to quote the
 * rate per cutoff, so that is what replaces it. Both pay types are now FLAT per
 * cutoff and share one monthly-equivalent basis:
 *
 *     monthly       → basic = basic_monthly_salary ÷ 2,  gov basis = basic_monthly_salary
 *     semi_monthly  → basic = semi_monthly_rate,         gov basis = semi_monthly_rate × 2
 *
 * No days_worked multiplier remains anywhere in basic pay, so the anomaly
 * cannot recur. Attendance still drives OT, night differential, holiday premium
 * and tardiness/undertime for both pay types.
 *
 * Data conversion: daily_rate is reinterpreted as a per-cutoff figure, so the
 * stored value is scaled by (work_days_per_month ÷ 2) — 11 working days per
 * cutoff at the default divisor of 22.
 *
 * payrolls.pay_type is a snapshot of what was in force when the run was
 * computed and is deliberately NOT rewritten — falsifying settled payroll
 * history would break the audit trail. Its column is widened instead so the
 * longer 'semi_monthly' value fits going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        $perCutoff = $this->workDaysPerCutoff();

        // ─── Widen pay_type columns ─────────────────────────────
        // 'semi_monthly' is 12 chars; both columns were varchar(10).
        Schema::table('employees', function (Blueprint $table) {
            $table->string('pay_type', 20)->change();
        });
        Schema::table('payrolls', function (Blueprint $table) {
            $table->string('pay_type', 20)->change();
        });

        // ─── Rename the rate columns to their new meaning ───────
        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('daily_rate', 'semi_monthly_rate');
        });
        if (Schema::hasTable('employee_salary_history')) {
            Schema::table('employee_salary_history', function (Blueprint $table) {
                $table->renameColumn('daily_rate', 'semi_monthly_rate');
            });
        }
        if (Schema::hasTable('salary_adjustments')) {
            Schema::table('salary_adjustments', function (Blueprint $table) {
                $table->renameColumn('from_daily_rate', 'from_semi_monthly_rate');
                $table->renameColumn('to_daily_rate', 'to_semi_monthly_rate');
            });
        }

        // ─── Rescale the values that were daily figures ─────────
        // Only rows belonging to a daily-paid employee held a real daily rate.
        $dailyEmployeeIds = DB::table('employees')->where('pay_type', 'daily')->pluck('id');

        DB::table('employees')
            ->whereIn('id', $dailyEmployeeIds)
            ->update([
                'pay_type'          => 'semi_monthly',
                'semi_monthly_rate' => DB::raw("ROUND(semi_monthly_rate * {$perCutoff}, 2)"),
                'updated_at'        => now(),
            ]);

        // Monthly staff may carry a stale legacy daily figure. Left in place it
        // would now read as a per-cutoff rate an order of magnitude below their
        // real pay, so clear it — basic_monthly_salary is their only basis.
        DB::table('employees')
            ->where('pay_type', 'monthly')
            ->whereNotNull('semi_monthly_rate')
            ->update(['semi_monthly_rate' => null]);

        if (Schema::hasTable('employee_salary_history')) {
            DB::table('employee_salary_history')
                ->whereIn('employee_id', $dailyEmployeeIds)
                ->whereNotNull('semi_monthly_rate')
                ->update(['semi_monthly_rate' => DB::raw("ROUND(semi_monthly_rate * {$perCutoff}, 2)")]);

            DB::table('employee_salary_history')
                ->whereNotIn('employee_id', $dailyEmployeeIds)
                ->whereNotNull('semi_monthly_rate')
                ->update(['semi_monthly_rate' => null]);
        }

        if (Schema::hasTable('salary_adjustments')) {
            DB::table('salary_adjustments')
                ->whereIn('employee_id', $dailyEmployeeIds)
                ->update([
                    'from_semi_monthly_rate' => DB::raw("ROUND(from_semi_monthly_rate * {$perCutoff}, 2)"),
                    'to_semi_monthly_rate'   => DB::raw("ROUND(to_semi_monthly_rate * {$perCutoff}, 2)"),
                ]);

            DB::table('salary_adjustments')
                ->whereNotIn('employee_id', $dailyEmployeeIds)
                ->update([
                    'from_semi_monthly_rate' => null,
                    'to_semi_monthly_rate'   => null,
                ]);
        }
    }

    public function down(): void
    {
        $perCutoff = $this->workDaysPerCutoff();

        $semiMonthlyIds = DB::table('employees')->where('pay_type', 'semi_monthly')->pluck('id');

        DB::table('employees')
            ->whereIn('id', $semiMonthlyIds)
            ->update([
                'pay_type'          => 'daily',
                'semi_monthly_rate' => DB::raw("ROUND(semi_monthly_rate / {$perCutoff}, 2)"),
                'updated_at'        => now(),
            ]);

        if (Schema::hasTable('salary_adjustments')) {
            Schema::table('salary_adjustments', function (Blueprint $table) {
                $table->renameColumn('from_semi_monthly_rate', 'from_daily_rate');
                $table->renameColumn('to_semi_monthly_rate', 'to_daily_rate');
            });
        }
        if (Schema::hasTable('employee_salary_history')) {
            Schema::table('employee_salary_history', function (Blueprint $table) {
                $table->renameColumn('semi_monthly_rate', 'daily_rate');
            });
        }
        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('semi_monthly_rate', 'daily_rate');
        });
    }

    /** Working days in one semi-monthly cutoff (default 22 ÷ 2 = 11). */
    private function workDaysPerCutoff(): string
    {
        $value = DB::table('settings')->where('key', 'payroll.work_days_per_month')->value('value');
        $perMonth = is_numeric($value) && (float) $value > 0 ? (string) $value : '22';

        return bcdiv($perMonth, '2', 4);
    }
};
