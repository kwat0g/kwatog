<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll correctness fixes.
 *
 * 1. `payrolls.tardiness_deduction` / `undertime_deduction`
 *    The calculator subtracts both from gross pay but never persisted them, so
 *    PayrollGlPostingService could not build a balanced journal entry: it
 *    debited full earnings while crediting a net that was already reduced by
 *    the lateness. Every payroll GL post therefore threw "unbalanced" and no
 *    finalized period had ever reached the ledger. Storing the amounts lets the
 *    posting service credit them back as a contra-expense line, and lets the
 *    payslip itemise the deduction the employee actually took.
 *
 * 2. `payroll_periods.status = 'computed'`
 *    Draft previously meant BOTH "never computed" and "computed, awaiting
 *    approval", which is what let the Compute button stay enabled after a
 *    successful run. Any existing draft period that already has payroll rows is
 *    really a completed run, so backfill it to the new Computed status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('tardiness_deduction', 15, 2)->default(0)->after('holiday_pay');
            $table->decimal('undertime_deduction', 15, 2)->default(0)->after('tardiness_deduction');
        });

        // Draft + has payroll rows == a finished compute run under the old
        // conflated status. Periods that never ran keep status = draft.
        DB::table('payroll_periods')
            ->where('status', 'draft')
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('payrolls')
                ->whereColumn('payrolls.payroll_period_id', 'payroll_periods.id'))
            ->update(['status' => 'computed']);
    }

    public function down(): void
    {
        DB::table('payroll_periods')
            ->where('status', 'computed')
            ->update(['status' => 'draft']);

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['tardiness_deduction', 'undertime_deduction']);
        });
    }
};
