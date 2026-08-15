<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\DeductionType;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollCycleClaim;
use App\Modules\Payroll\Models\PayrollDeductionDetail;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\ThirteenthMonthAccrual;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 13th-month pay tracking.
 *
 * Sprint 3 builds the accrual hook into PayrollCalculatorService.
 * Sprint 3 / Task 28 adds the December run (`computeAndPay`) that creates the
 * dedicated payroll period and disburses the accrued amount.
 */
class ThirteenthMonthService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Increment the running accrual for an employee + year by this payroll's
     * basic pay. Idempotent via UNIQUE (employee_id, year).
     *
     * Skipped when the payroll itself is part of a 13th-month period (we never
     * accrue the payout against itself).
     */
    public function accrue(Payroll $payroll): ?ThirteenthMonthAccrual
    {
        $period = $payroll->relationLoaded('period') ? $payroll->period : $payroll->period()->first();
        if (! $period || $period->is_thirteenth_month) {
            return null;
        }

        $year = (int) $period->period_start->format('Y');

        return DB::transaction(function () use ($payroll, $year) {
            // Serialize on the employee row so concurrent accruals for the same
            // employee/year cannot double-create the accrual (no unique index)
            // or lose a period's contribution from a stale running total.
            Employee::query()->lockForUpdate()->findOrFail($payroll->employee_id);

            $accrual = ThirteenthMonthAccrual::firstOrCreate(
                ['employee_id' => $payroll->employee_id, 'year' => $year],
                ['total_basic_earned' => '0.00', 'accrued_amount' => '0.00', 'is_paid' => false],
            );

            // Don't double-accrue once paid for the year.
            if ($accrual->is_paid) {
                return $accrual;
            }

            $newTotal = Money::add((string) $accrual->total_basic_earned, (string) $payroll->basic_pay);
            $accrual->total_basic_earned = $newTotal;
            $accrual->accrued_amount     = Money::div($newTotal, '12', 2); // running estimate
            $accrual->save();

            return $accrual;
        });
    }

    /**
     * Roll back the accrual contribution made by a payroll that is being
     * replaced by a recompute.
     *
     * accrue() adds basic_pay to a running total, so without this every
     * recompute of the same period inflated the employee's 13th-month base by
     * another half-month of basic pay. Called by PayrollCalculatorService just
     * before the old payroll row is deleted.
     */
    public function reverseAccrual(Payroll $payroll): ?ThirteenthMonthAccrual
    {
        $period = $payroll->relationLoaded('period') ? $payroll->period : $payroll->period()->first();
        if (! $period || $period->is_thirteenth_month) {
            return null;
        }

        $year = (int) $period->period_start->format('Y');

        return DB::transaction(function () use ($payroll, $year) {
            // Same serialization as accrue(): a recompute racing another
            // accrual must not read-modify-write the total from a stale row.
            Employee::query()->lockForUpdate()->findOrFail($payroll->employee_id);

            $accrual = ThirteenthMonthAccrual::query()
                ->where('employee_id', $payroll->employee_id)
                ->where('year', $year)
                ->first();

            // Once the year has been paid out the accrual is closed — accrue()
            // refuses to add to it, so there is nothing to take back either.
            if (! $accrual || $accrual->is_paid) {
                return $accrual;
            }

            $newTotal = Money::sub((string) $accrual->total_basic_earned, (string) $payroll->basic_pay);
            if (Money::lt($newTotal, '0')) {
                $newTotal = Money::zero();
            }
            $accrual->total_basic_earned = $newTotal;
            $accrual->accrued_amount     = Money::div($newTotal, '12', 2);
            $accrual->save();

            return $accrual;
        });
    }

    public function getAccrual(Employee $employee, int $year): ?ThirteenthMonthAccrual
    {
        return ThirteenthMonthAccrual::query()
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->first();
    }

    /**
     * Run the December 13th-month batch:
     *   1. Create a special PayrollPeriod (`is_thirteenth_month=true`)
     *   2. For each employee with an unpaid accrual:
     *      - finalize accrued_amount = total_basic_earned / 12
     *      - create a Payroll row with gross/net = accrued_amount and a
     *        ThirteenthMonth deduction-detail line so the payslip prints
     *        the line item.
     *      - mark accrual paid + linked
     *
     * The run applies the effective ₱90,000 exemption and reconciles annual
     * withholding against the BIR Annex D/E schedule before it reaches the
     * maker-checker approval boundary.
     */
    public function computeAndPay(int $year, User $triggeredBy, ?string $payrollDate = null): PayrollPeriod
    {
        try {
            return DB::transaction(function () use ($year, $triggeredBy, $payrollDate) {
                // The partial unique index is the durable backstop. This lock makes
                // the read/decide/create sequence deterministic for concurrent
                // callers on PostgreSQL; SQLite test connections deliberately no-op.
                $this->lockThirteenthMonthYear($year);

                $payDay = $this->settings->requiredInt('payroll.thirteenth_month.default_pay_day', 1, 31);
                $payDate = $payrollDate
                    ? CarbonImmutable::parse($payrollDate)
                    : CarbonImmutable::create($year, 12, $payDay);

                // Re-read after the advisory lock. Do not select a voided period:
                // voiding is the explicit lifecycle operation that permits a
                // replacement run for the same calendar year.
                $existing = PayrollPeriod::query()
                    ->where('is_thirteenth_month', true)
                    ->where('status', '!=', PayrollPeriodStatus::Voided->value)
                    ->whereYear('period_start', $year)
                    ->orderBy('id')
                    ->first();

                if ($existing && $existing->status === PayrollPeriodStatus::Finalized) {
                    throw new BusinessRuleException("13th-month period for {$year} is already finalized.");
                }

                if ($existing && $existing->status === PayrollPeriodStatus::Processing) {
                    throw new BusinessRuleException(
                        "13th-month period for {$year} is already being computed. Wait for the current run to finish."
                    );
                }

                if ($existing && ! $existing->status?->isComputable()) {
                    throw new BusinessRuleException(sprintf(
                        'Cannot recompute the %s 13th-month period for %d. Void it or force-unlock it first.',
                        strtolower($existing->status?->label() ?? 'unknown'),
                        $year,
                    ));
                }

                if ($existing) {
                    $period = $existing;
                } else {
                    $period = PayrollPeriod::create([
                        'period_start' => "{$year}-12-01",
                        'period_end' => "{$year}-12-31",
                        'payroll_date' => $payDate->toDateString(),
                        'is_first_half' => false,
                        'is_thirteenth_month' => true,
                        'created_by' => $triggeredBy->id,
                    ]);
                    // status non-fillable; service-only.
                    $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();
                }

                // One owner token spans compute/retry for this annual run;
                // voiding is the explicit correction boundary.
                $period->forceFill([
                    'thirteenth_month_run_token' => (string) Str::uuid(),
                    'thirteenth_month_run_state' => 'computing',
                ])->save();

                // Wipe any partial run so this is idempotent.
                $oldPayrollIds = Payroll::where('payroll_period_id', $period->id)->pluck('id');
                PayrollDeductionDetail::whereIn('payroll_id', $oldPayrollIds)->delete();
                // Clear the FK reference on accruals before deleting the payroll rows;
                // otherwise SQLite (and strict FK DBs) raise a constraint violation.
                // Lock the owning employee rows first so lock ordering matches
                // accrue()/reverseAccrual() (employee → accrual) — a concurrent
                // normal-payroll accrue cannot deadlock against this year-end payout.
                ThirteenthMonthAccrual::query()
                    ->whereIn('payroll_id', $oldPayrollIds)
                    ->pluck('employee_id')
                    ->unique()
                    ->each(fn (int $empId) => Employee::query()->lockForUpdate()->findOrFail($empId));
                ThirteenthMonthAccrual::whereIn('payroll_id', $oldPayrollIds)
                    ->update(['payroll_id' => null]);
                Payroll::whereIn('id', $oldPayrollIds)->delete();

                $accruals = ThirteenthMonthAccrual::query()
                    ->where('year', $year)
                    ->where('is_paid', false)
                    ->whereHas('employee', fn ($q) => $q->where('status', EmployeeStatus::Active->value))
                    ->with('employee')
                    ->get();

                foreach ($accruals as $accrual) {
                    $emp = $accrual->employee;
                    if (! $emp) {
                        continue;
                    }

                    // Final canonical amount = total_basic_earned / 12.
                    $amount = Money::div((string) $accrual->total_basic_earned, '12', 2);
                    $amount = Money::round2($amount);
                    if (Money::isZero($amount)) {
                        continue;
                    }

                    $rule = DB::table('thirteenth_month_tax_rules')
                        ->whereDate('effective_from', '<=', $payDate->toDateString())
                        ->orderByDesc('effective_from')->first();
                    $exemption = (string) ($rule->exemption_amount ?? '90000.00');
                    $priorBenefit = (string) Payroll::query()
                        ->join('payroll_periods as prior_period', 'prior_period.id', '=', 'payrolls.payroll_period_id')
                        ->where('payrolls.employee_id', $emp->id)
                        ->where('prior_period.is_thirteenth_month', true)
                        ->whereYear('prior_period.period_start', $year)
                        ->whereIn('prior_period.status', [PayrollPeriodStatus::Finalized->value, PayrollPeriodStatus::Disbursed->value])
                        ->sum('payrolls.gross_pay');
                    $taxableExcess = Money::sub(Money::add($priorBenefit, $amount), $exemption);
                    if (Money::lt($taxableExcess, '0')) $taxableExcess = Money::zero();

                    // Annualize the employee's YTD taxable compensation using
                    // the same cent-precise basis as regular payroll: gross
                    // less employee statutory shares, plus taxable benefit
                    // portions. Add the 13th-month excess only once.
                    $regularTaxable = (string) DB::table('payrolls as ytd')
                        ->join('payroll_periods as ytd_period', 'ytd_period.id', '=', 'ytd.payroll_period_id')
                        ->where('ytd.employee_id', $emp->id)
                        ->where('ytd_period.is_thirteenth_month', false)
                        ->whereYear('ytd_period.period_start', $year)
                        ->whereIn('ytd_period.status', [PayrollPeriodStatus::Finalized->value, PayrollPeriodStatus::Disbursed->value])
                        ->selectRaw('COALESCE(SUM(ytd.gross_pay - ytd.sss_ee - ytd.philhealth_ee - ytd.pagibig_ee), 0) AS taxable_total')
                        ->value('taxable_total');
                    $otherBenefitExcess = (string) DB::table('de_minimis_benefits')
                        ->where('employee_id', $emp->id)
                        ->where('period_year', $year)
                        ->where('is_taxable_portion', true)
                        ->sum('amount');
                    $annualTaxable = Money::add($regularTaxable, $otherBenefitExcess, $taxableExcess);
                    $annualTaxDue = $this->annualTaxDue($annualTaxable, $payDate->toDateString());
                    $priorWithheld = (string) DB::table('payrolls as prior')
                        ->join('payroll_periods as prior_period', 'prior_period.id', '=', 'prior.payroll_period_id')
                        ->where('prior.employee_id', $emp->id)
                        ->whereYear('prior_period.period_start', $year)
                        ->whereIn('prior_period.status', [PayrollPeriodStatus::Finalized->value, PayrollPeriodStatus::Disbursed->value])
                        ->sum('prior.withholding_tax');
                    $correctionDelta = Money::sub($annualTaxDue, $priorWithheld);
                    if (Money::lt($correctionDelta, '0')) $correctionDelta = Money::zero();

                    $payroll = Payroll::create([
                        'payroll_period_id' => $period->id,
                        'employee_id' => $emp->id,
                        'pay_type' => $emp->pay_type instanceof \BackedEnum ? $emp->pay_type->value : (string) $emp->pay_type,
                        'days_worked' => null,
                        'basic_pay' => '0.00',
                        'overtime_pay' => '0.00',
                        'night_diff_pay' => '0.00',
                        'holiday_pay' => '0.00',
                        'gross_pay' => $amount,
                        'sss_ee' => '0.00', 'sss_er' => '0.00',
                        'philhealth_ee' => '0.00', 'philhealth_er' => '0.00',
                        'pagibig_ee' => '0.00', 'pagibig_er' => '0.00',
                        'withholding_tax' => $correctionDelta,
                        'thirteenth_month_taxable_excess' => $taxableExcess,
                        'thirteenth_month_correction_delta' => $correctionDelta,
                        'loan_deductions' => '0.00', 'other_deductions' => '0.00',
                        'adjustment_amount' => '0.00',
                        'total_deductions' => $correctionDelta,
                        'net_pay' => Money::sub($amount, $correctionDelta),
                        'computed_at' => now(),
                    ]);

                    PayrollDeductionDetail::create([
                        'payroll_id' => $payroll->id,
                        'deduction_type' => DeductionType::ThirteenthMonth->value,
                        'description' => '13th Month Pay · '.$year,
                        'amount' => $amount,
                    ]);
                    if (! Money::isZero($correctionDelta)) {
                        PayrollDeductionDetail::create([
                            'payroll_id' => $payroll->id,
                            'deduction_type' => DeductionType::WithholdingTax->value,
                            'description' => 'BIR annual 13th-month correction · '.$year,
                            'amount' => $correctionDelta,
                        ]);
                    }

                    // The accrual is linked to its payroll row and its final amount
                    // is recorded, but it is NOT marked paid here.
                    //
                    // computeAndPay() used to flip is_paid on a period still sitting
                    // at Draft, so the year's 13th month read as settled before any
                    // checker had seen it — and nothing downstream would ever pay it
                    // again, since accrue() skips a paid accrual. Payment is now
                    // recognised only when the period is finalized (see
                    // markAccrualsPaidOnFinalize), which is the same maker-checker
                    // gate REC-04 puts on every other material batch.
                    $accrual->accrued_amount = $amount;
                    $accrual->payroll_id = $payroll->id;
                    $accrual->save();

                    // Stake the year's 13th-month cycle so a second run cannot pay
                    // the same employee twice. Mirrors the guard the semi-monthly
                    // calculator applies; the unique index is what enforces it.
                    $this->claimThirteenthMonthCycle($payroll, $period, $emp);
                }

                // Computed, NOT Draft: rows exist and are awaiting approval. Parking
                // at Draft made a finished run indistinguishable from an untouched
                // one — the same conflation the status split removed for the
                // semi-monthly pipeline.
                $period->forceFill([
                    'status'      => PayrollPeriodStatus::Computed->value,
                    'computed_by' => $triggeredBy->id,
                    'thirteenth_month_run_state' => 'computed',
                    'tax_reconciliation_hash' => hash('sha256', $period->id.':'.$year.':'.Payroll::where('payroll_period_id', $period->id)->sum('thirteenth_month_correction_delta')),
                    'tax_reconciliation_signed_at' => now(),
                ])->save();

                return $period->fresh();
            });
        } catch (QueryException $e) {
            if (! $this->isThirteenthMonthUniqueViolation($e)) {
                throw $e;
            }

            // A writer outside this service may still race the index. Re-read
            // the authoritative row and expose the same stable state errors as
            // the normal path rather than leaking a driver-specific SQL error.
            $existing = PayrollPeriod::query()
                ->where('is_thirteenth_month', true)
                ->where('status', '!=', PayrollPeriodStatus::Voided->value)
                ->whereYear('period_start', $year)
                ->orderBy('id')
                ->first();

            if ($existing?->status === PayrollPeriodStatus::Finalized) {
                throw new BusinessRuleException("13th-month period for {$year} is already finalized.");
            }

            throw new BusinessRuleException(
                "13th-month period for {$year} already exists. Refresh and retry the existing run."
            );
        }
    }

    /**
     * Serialize all creation/replay decisions for a calendar year.
     * PostgreSQL transaction advisory locks are released automatically at the
     * end of the surrounding DB transaction; SQLite has no equivalent.
     */
    private function lockThirteenthMonthYear(int $year): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('SELECT pg_advisory_xact_lock(?::integer, ?::integer)', [13013, $year]);
    }

    /** Compute annual BIR tax from the effective Annex D/E schedule. */
    private function annualTaxDue(string $taxableCompensation, string $effectiveDate): string
    {
        if (Money::lte($taxableCompensation, '0')) {
            return Money::zero();
        }

        $effective = DB::table('payroll_annual_tax_brackets')
            ->whereDate('effective_from', '<=', $effectiveDate)
            ->max('effective_from');
        $bracket = DB::table('payroll_annual_tax_brackets')
            ->whereDate('effective_from', $effective)
            ->where('bracket_min', '<=', $taxableCompensation)
            ->where('bracket_max', '>=', $taxableCompensation)
            ->first();
        if (! $bracket) {
            throw new BusinessRuleException('No effective BIR annual withholding schedule is configured for '.$effectiveDate.'.');
        }

        $excess = bcsub($taxableCompensation, (string) $bracket->bracket_min, 4);
        return Money::round2(bcadd((string) $bracket->fixed_tax, bcmul($excess, (string) $bracket->rate_on_excess, 4), 4));
    }

    private function isThirteenthMonthUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return in_array((string) $exception->getCode(), ['23505', '23000'], true)
            && str_contains($message, 'payroll_periods_thirteenth_month_year_unique');
    }

    /**
     * Recognise payment once a 13th-month period is finalized.
     *
     * Called by the PayrollPeriodFinalized listener path so is_paid flips only
     * after approve() + finalize() have both run. Idempotent: an already-paid
     * accrual is left alone.
     */
    public function markAccrualsPaidOnFinalize(PayrollPeriod $period): int
    {
        if (! $period->is_thirteenth_month) {
            return 0;
        }

        $year = (int) $period->period_start->format('Y');
        $paidDate = $period->payroll_date ?? $period->period_end;

        return ThirteenthMonthAccrual::query()
            ->where('year', $year)
            ->where('is_paid', false)
            ->whereNotNull('payroll_id')
            ->whereIn('payroll_id', Payroll::where('payroll_period_id', $period->id)->select('id'))
            ->update([
                'is_paid'    => true,
                'paid_date'  => $paidDate instanceof \DateTimeInterface
                    ? $paidDate->format('Y-m-d')
                    : (string) $paidDate,
                'updated_at' => now(),
            ]);
    }

    /**
     * Claim the year's 13th-month cycle for this employee.
     *
     * The 13th month is its own cycle key ('YYYY-13TH'), so it never collides
     * with a semi-monthly cutoff. Two 13th-month runs for one year would, which
     * is exactly what this prevents.
     */
    private function claimThirteenthMonthCycle(Payroll $payroll, PayrollPeriod $period, Employee $employee): void
    {
        $cycleKey = $period->cycleKey();

        $holder = PayrollCycleClaim::query()
            ->where('employee_id', $employee->id)
            ->where('cycle_key', $cycleKey)
            ->first();

        if ($holder) {
            // A re-run of the SAME period is legitimate: the wipe above deleted
            // the old payroll rows, and claims cascade on payroll_id, so any
            // surviving claim belongs to a different period.
            if ((int) $holder->payroll_period_id === (int) $period->id) {
                return;
            }

            throw new BusinessRuleException(sprintf(
                'Employee %s has already received 13th-month pay for %s under another period.',
                $employee->employee_no,
                $period->period_start->format('Y'),
            ));
        }

        PayrollCycleClaim::create([
            'employee_id'       => $employee->id,
            'payroll_id'        => $payroll->id,
            'payroll_period_id' => $period->id,
            'cycle_key'         => $cycleKey,
        ]);
    }
}
