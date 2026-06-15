<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Enums\PayrollAdjustmentType;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PayrollCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private PayrollCalculatorService $calc;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->calc = app(PayrollCalculatorService::class);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        $dept = Department::create(['name' => 'Production', 'code' => 'PRD']);
        $pos  = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        return Employee::create(array_merge([
            'employee_no'          => 'OGM-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'first_name'           => 'Juan',
            'last_name'            => 'Dela Cruz',
            'birth_date'           => '1990-01-01',
            'gender'               => 'male',
            'civil_status'         => 'single',
            'nationality'          => 'Filipino',
            'street_address'       => '123 Main',
            'city'                 => 'Dasmariñas',
            'province'             => 'Cavite',
            'mobile_number'        => '09171234567',
            'email'                => 'jdc@example.com',
            'emergency_contact_name'  => 'Maria',
            'emergency_contact_phone' => '09181234567',
            'department_id'        => $dept->id,
            'position_id'          => $pos->id,
            'employment_type'      => 'regular',
            'pay_type'             => 'monthly',
            'date_hired'           => '2025-01-01',
            'basic_monthly_salary' => '20000.00',
            'status'               => 'active',
        ], $overrides));
    }

    private function makePeriod(bool $firstHalf = true, ?string $start = null, ?string $end = null): PayrollPeriod
    {
        $roleId = \App\Modules\Auth\Models\Role::query()->orderBy('id')->value('id');
        $userId = \App\Modules\Auth\Models\User::create([
            'name'     => 'Tester',
            'email'    => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ])->id;

        $start = $start ?? '2026-04-01';
        $end   = $end   ?? '2026-04-15';

        $period = PayrollPeriod::create([
            'period_start'  => $start,
            'period_end'    => $end,
            'payroll_date'  => $end,
            'is_first_half' => $firstHalf,
            'is_thirteenth_month' => false,
            'created_by'    => $userId,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();
        return $period;
    }

    private function attendanceFor(Employee $emp, string $start, string $end, float $hoursPerDay = 8.0): void
    {
        $cur = \Carbon\Carbon::parse($start);
        $endC = \Carbon\Carbon::parse($end);
        while ($cur->lte($endC)) {
            // Skip Sundays for a 22-day month feel.
            if ($cur->dayOfWeek !== 0) {
                Attendance::create([
                    'employee_id'    => $emp->id,
                    'date'           => $cur->toDateString(),
                    'time_in'        => $cur->copy()->setTime(8, 0)->toDateTimeString(),
                    'time_out'       => $cur->copy()->setTime((int) floor(8 + $hoursPerDay), (int) (60 * fmod(8 + $hoursPerDay, 1)))->toDateTimeString(),
                    'regular_hours'  => $hoursPerDay,
                    'overtime_hours' => 0,
                    'night_diff_hours' => 0,
                    'tardiness_minutes' => 0,
                    'undertime_minutes' => 0,
                    'is_rest_day'    => false,
                    'day_type_rate'  => 1.00,
                    'status'         => 'present',
                ]);
            }
            $cur->addDay();
        }
    }

    // ─── Tests ────────────────────────────────────────────────

    public function test_monthly_first_half_full_attendance(): void
    {
        $emp = $this->makeEmployee(); // 20,000 monthly
        $period = $this->makePeriod(true, '2026-04-01', '2026-04-15');
        $this->attendanceFor($emp, '2026-04-01', '2026-04-15');

        $payroll = $this->calc->computeForEmployee($period, $emp);

        // basic_pay = 20000/2 = 10000
        $this->assertSame('10000.00', $payroll->basic_pay);
        // gross with no OT/holidays = basic
        $this->assertSame('10000.00', $payroll->gross_pay);
        // Gov deductions present (first half)
        $this->assertNotSame('0.00', $payroll->sss_ee);
        $this->assertNotSame('0.00', $payroll->philhealth_ee);
        $this->assertNotSame('0.00', $payroll->pagibig_ee);
        // Net pay > 0
        $this->assertGreaterThan(0, (float) $payroll->net_pay);
        // Detail rows for SSS / PhilHealth / Pag-IBIG (WHT > 0 since taxable > 10416)
        $this->assertGreaterThanOrEqual(3, $payroll->deductionDetails->count());
    }

    public function test_monthly_second_half_skips_government_deductions(): void
    {
        $emp = $this->makeEmployee();
        $period = $this->makePeriod(false, '2026-04-16', '2026-04-30');
        $this->attendanceFor($emp, '2026-04-16', '2026-04-30');

        $payroll = $this->calc->computeForEmployee($period, $emp);

        $this->assertSame('0.00', $payroll->sss_ee);
        $this->assertSame('0.00', $payroll->philhealth_ee);
        $this->assertSame('0.00', $payroll->pagibig_ee);
        $this->assertSame('0.00', $payroll->withholding_tax);
    }

    public function test_daily_rate_employee(): void
    {
        $emp = $this->makeEmployee([
            'pay_type'             => 'daily',
            'basic_monthly_salary' => null,
            'daily_rate'           => '600.00',
        ]);
        $period = $this->makePeriod(true, '2026-04-01', '2026-04-15');
        $this->attendanceFor($emp, '2026-04-01', '2026-04-15');

        $payroll = $this->calc->computeForEmployee($period, $emp);

        // 13 working days (Apr 1-15 minus 2 Sundays Apr 5, 12) × 600 = 7800
        $this->assertSame('13.0', (string) $payroll->days_worked);
        $this->assertSame('7800.00', $payroll->basic_pay);
    }

    public function test_mid_period_hire_pro_rates_basic(): void
    {
        $emp = $this->makeEmployee(['date_hired' => '2026-04-08']);
        $period = $this->makePeriod(true, '2026-04-01', '2026-04-15');
        $this->attendanceFor($emp, '2026-04-08', '2026-04-15');

        $payroll = $this->calc->computeForEmployee($period, $emp);

        // 8 days hired / 15 days total ≈ 0.5333
        // basic = 10000 × 0.5333 = ~5333.33
        $this->assertGreaterThan(0, (float) $payroll->basic_pay);
        $this->assertLessThan(10000, (float) $payroll->basic_pay);
    }

    public function test_active_loan_deducted_and_payment_recorded(): void
    {
        $emp = $this->makeEmployee();
        $period = $this->makePeriod(true, '2026-04-01', '2026-04-15');
        $this->attendanceFor($emp, '2026-04-01', '2026-04-15');

        $loan = EmployeeLoan::create([
            'loan_no'              => 'LN-202604-0001',
            'employee_id'          => $emp->id,
            'loan_type'            => LoanType::CompanyLoan->value,
            'principal'            => '6000.00',
            'monthly_amortization' => '1000.00', // 6 months
            'total_paid'           => '0.00',
            'balance'              => '6000.00',
            'pay_periods_total'    => 12,
            'pay_periods_remaining' => 12,
            'start_date'           => '2026-04-01',
        ]);
        $loan->forceFill(['status' => LoanStatus::Active->value])->save();

        $payroll = $this->calc->computeForEmployee($period, $emp);

        // Half of monthly amortization = 500 per semi-monthly period
        $this->assertSame('500.00', $payroll->loan_deductions);
        $this->assertDatabaseHas('loan_payments', ['loan_id' => $loan->id, 'amount' => 500.00]);
        $loan->refresh();
        $this->assertSame('5500.00', (string) $loan->balance);
        $this->assertSame(11, $loan->pay_periods_remaining);
    }

    public function test_recompute_replaces_previous_payroll(): void
    {
        $emp = $this->makeEmployee();
        $period = $this->makePeriod(true, '2026-04-01', '2026-04-15');
        $this->attendanceFor($emp, '2026-04-01', '2026-04-15');

        $first  = $this->calc->computeForEmployee($period, $emp);
        $second = $this->calc->computeForEmployee($period, $emp);

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseMissing('payrolls', ['id' => $first->id]);
        $this->assertDatabaseCount('payrolls', 1);
    }

    public function test_finalized_period_blocks_compute(): void
    {
        $emp = $this->makeEmployee();
        $period = $this->makePeriod(true, '2026-04-01', '2026-04-15');
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value])->save();

        $this->expectException(\RuntimeException::class);
        $this->calc->computeForEmployee($period, $emp);
    }

    public function test_thirteenth_month_accrual_increments(): void
    {
        $emp = $this->makeEmployee();
        $period1 = $this->makePeriod(true, '2026-04-01', '2026-04-15');
        $period2 = $this->makePeriod(false, '2026-04-16', '2026-04-30');
        $this->attendanceFor($emp, '2026-04-01', '2026-04-30');

        $this->calc->computeForEmployee($period1, $emp);
        $this->calc->computeForEmployee($period2, $emp);

        $this->assertDatabaseHas('thirteenth_month_accruals', [
            'employee_id'        => $emp->id,
            'year'               => 2026,
            'total_basic_earned' => 20000.00,
        ]);
    }

    public function test_negative_net_pay_clamped_to_zero(): void
    {
        // Tiny salary, mandatory loan that wipes out basic
        $emp = $this->makeEmployee([
            'pay_type'             => 'daily',
            'basic_monthly_salary' => null,
            'daily_rate'           => '50.00',
        ]);
        $period = $this->makePeriod(true, '2026-04-01', '2026-04-15');
        $this->attendanceFor($emp, '2026-04-01', '2026-04-15');

        $caLoan = EmployeeLoan::create([
            'loan_no' => 'LN-X', 'employee_id' => $emp->id,
            'loan_type' => LoanType::CashAdvance->value,
            'principal' => '5000.00', 'monthly_amortization' => '5000.00',
            'total_paid' => '0.00', 'balance' => '5000.00',
            'pay_periods_total' => 1, 'pay_periods_remaining' => 1,
            'start_date' => '2026-04-01',
        ]);
        $caLoan->forceFill(['status' => LoanStatus::Active->value])->save();

        $payroll = $this->calc->computeForEmployee($period, $emp);

        $this->assertSame('0.00', $payroll->net_pay);
    }

    /**
     * Monthly employee with OT and night-diff on the SAME attendance row.
     *
     * Setup: one day, 2 OT hours (× 1.25 rate) + 3 night-diff hours (+ 10% premium).
     * Both premiums are additive and should not interfere with each other.
     *
     * Rates (20 000 monthly):
     *   hourly      = 20000 / 22 / 8 ≈ 113.6363…
     *   ot_pay      = 2 × hourly × 1.25 × 1.0  = 284.09
     *   nd_pay      = 3 × hourly × 0.10         =  34.09
     *   basic_pay   = 20000 / 2                 = 10000.00
     *   gross_pay   = 10000.00 + 284.09 + 34.09 = 10318.18
     */
    public function test_monthly_ot_and_night_diff_stack_on_same_day(): void
    {
        $emp    = $this->makeEmployee(); // 20,000 monthly
        $period = $this->makePeriod(false, '2026-04-16', '2026-04-30'); // second half: no gov deductions

        // Single day with 8 regular hours + 2 OT hours + 3 night-diff hours.
        Attendance::create([
            'employee_id'        => $emp->id,
            'date'               => '2026-04-16',
            'time_in'            => '2026-04-16 08:00:00',
            'time_out'           => '2026-04-16 21:00:00',
            'regular_hours'      => '8.00',
            'overtime_hours'     => '2.00',
            'night_diff_hours'   => '3.00',
            'tardiness_minutes'  => 0,
            'undertime_minutes'  => 0,
            'is_rest_day'        => false,
            'day_type_rate'      => '1.00',
            'status'             => 'present',
        ]);

        $payroll = $this->calc->computeForEmployee($period, $emp);

        // Each premium is independent: OT and ND do not cross-multiply.
        $this->assertSame('284.09', $payroll->overtime_pay);
        $this->assertSame('34.09',  $payroll->night_diff_pay);
        $this->assertSame('10000.00', $payroll->basic_pay);
        $this->assertSame('10318.18', $payroll->gross_pay);

        // No gov deductions on second half.
        $this->assertSame('0.00', $payroll->sss_ee);
        $this->assertSame('0.00', $payroll->withholding_tax);
    }

    /**
     * Daily-rated employee with OT and night-diff stacking on the same day.
     *
     * Rates (800 daily):
     *   hourly  = 800 / 8 = 100.00
     *   ot_pay  = 2 × 100 × 1.25 × 1.0 = 250.00
     *   nd_pay  = 3 × 100 × 0.10        =  30.00
     *   basic   = 1 day × 800            = 800.00
     *   gross   = 800 + 250 + 30         = 1080.00
     */
    public function test_daily_rate_ot_and_night_diff_stack_on_same_day(): void
    {
        $emp = $this->makeEmployee([
            'pay_type'             => 'daily',
            'basic_monthly_salary' => null,
            'daily_rate'           => '800.00',
        ]);
        $period = $this->makePeriod(false, '2026-04-16', '2026-04-30');

        Attendance::create([
            'employee_id'        => $emp->id,
            'date'               => '2026-04-16',
            'time_in'            => '2026-04-16 08:00:00',
            'time_out'           => '2026-04-16 21:00:00',
            'regular_hours'      => '8.00',
            'overtime_hours'     => '2.00',
            'night_diff_hours'   => '3.00',
            'tardiness_minutes'  => 0,
            'undertime_minutes'  => 0,
            'is_rest_day'        => false,
            'day_type_rate'      => '1.00',
            'status'             => 'present',
        ]);

        $payroll = $this->calc->computeForEmployee($period, $emp);

        $this->assertSame('250.00', $payroll->overtime_pay);
        $this->assertSame('30.00',  $payroll->night_diff_pay);
        $this->assertSame('800.00', $payroll->basic_pay);
        $this->assertSame('1080.00', $payroll->gross_pay);
    }

    /**
     * Daily-rated employee works on a regular holiday (200% rule).
     *
     * The DTR engine sets day_type_rate = 2.0 for a regular holiday.
     * aggregateAttendance() calculates:
     *   holiday_pay = (2.0 − 1.0) × regular_hours × hourly_rate
     *
     * Rates (600 daily):
     *   hourly      = 600 / 8 = 75.00
     *   holiday_pay = 1.0 × 8 × 75 = 600.00   (the "premium" bit above 100%)
     *   basic_pay   = 1 day × 600  = 600.00   (the base 100%)
     *   gross_pay   = 600 + 600    = 1200.00
     *
     * Note: per service comments, holiday_pay captures only the EXTRA earned above
     * regular pay so basic_pay stays clean. Together they total 200% as required.
     */
    public function test_daily_rate_regular_holiday_200_percent_rule(): void
    {
        $emp = $this->makeEmployee([
            'pay_type'             => 'daily',
            'basic_monthly_salary' => null,
            'daily_rate'           => '600.00',
        ]);
        $period = $this->makePeriod(false, '2026-04-16', '2026-04-30');

        Attendance::create([
            'employee_id'        => $emp->id,
            'date'               => '2026-04-16',
            'time_in'            => '2026-04-16 08:00:00',
            'time_out'           => '2026-04-16 17:00:00',
            'regular_hours'      => '8.00',
            'overtime_hours'     => '0.00',
            'night_diff_hours'   => '0.00',
            'tardiness_minutes'  => 0,
            'undertime_minutes'  => 0,
            'is_rest_day'        => false,
            'day_type_rate'      => '2.00',   // regular holiday → 200%
            'status'             => 'present',
        ]);

        $payroll = $this->calc->computeForEmployee($period, $emp);

        // holiday_pay = extra 100% on top of the base 100% already in basic_pay
        $this->assertSame('600.00', $payroll->holiday_pay);
        $this->assertSame('600.00', $payroll->basic_pay);
        $this->assertSame('1200.00', $payroll->gross_pay);

        // No OT or ND on this day
        $this->assertSame('0.00', $payroll->overtime_pay);
        $this->assertSame('0.00', $payroll->night_diff_pay);
    }

    /**
     * applyApprovedAdjustments() behaviour — four assertions in one test:
     *
     *  A) A positive (underpayment) adjustment increases net pay.
     *  B) A negative (overpayment) adjustment decreases net pay.
     *  C) Two adjustments in the same period are both applied and their signed
     *     total accumulates correctly on adjustment_amount.
     *  D) Recompute does NOT double-apply: once an adjustment has applied_at set
     *     (status = Applied) it is skipped by whereNull('applied_at'), so the
     *     recomputed payroll shows adjustment_amount = 0.00.
     */
    public function test_approved_adjustments_affect_net_and_recompute_does_not_double_apply(): void
    {
        $emp         = $this->makeEmployee(); // 20,000 monthly
        $period1     = $this->makePeriod(false, '2026-04-16', '2026-04-30'); // anchor period for FK
        $period2     = $this->makePeriod(false, '2026-05-16', '2026-05-31'); // target period

        // Build a payroll row in period1 so we satisfy original_payroll_id FK.
        $this->attendanceFor($emp, '2026-04-16', '2026-04-30');
        $originalPayroll = $this->calc->computeForEmployee($period1, $emp);

        // Populate period2 attendance.
        $this->attendanceFor($emp, '2026-05-16', '2026-05-31');

        // Create two adjustments referencing period1/original payroll.
        // (The service picks them up by employee + status + applied_at=null; period is ignored.)
        $underpay = PayrollAdjustment::create([
            'payroll_period_id'   => $period1->id,
            'employee_id'         => $emp->id,
            'original_payroll_id' => $originalPayroll->id,
            'type'                => PayrollAdjustmentType::Underpayment->value,
            'amount'              => '500.00',
            'reason'              => 'Missed allowance',
        ]);
        $underpay->forceFill(['status' => PayrollAdjustmentStatus::Approved->value])->save();

        $overpay = PayrollAdjustment::create([
            'payroll_period_id'   => $period1->id,
            'employee_id'         => $emp->id,
            'original_payroll_id' => $originalPayroll->id,
            'type'                => PayrollAdjustmentType::Overpayment->value,
            'amount'              => '300.00',
            'reason'              => 'Extra paid last period',
        ]);
        $overpay->forceFill(['status' => PayrollAdjustmentStatus::Approved->value])->save();

        // (A)+(B)+(C): Compute period2 — both adjustments should apply.
        $payroll2 = $this->calc->computeForEmployee($period2, $emp);

        // signed total = +500 + (-300) = +200
        $this->assertSame('200.00', $payroll2->adjustment_amount);

        // Net must have increased by +200 compared to gross-minus-deductions
        $expectedNet = \App\Common\Support\Money::add(
            \App\Common\Support\Money::sub($payroll2->gross_pay, $payroll2->total_deductions),
            '200.00'
        );
        // net_pay is clamped at 0 but for a 20k employee it won't be
        $this->assertSame($expectedNet, $payroll2->net_pay);

        // Adjustments should now be marked Applied with applied_at set.
        $underpay->refresh();
        $overpay->refresh();
        $this->assertSame(PayrollAdjustmentStatus::Applied, $underpay->status);
        $this->assertNotNull($underpay->applied_at);
        $this->assertSame(PayrollAdjustmentStatus::Applied, $overpay->status);
        $this->assertNotNull($overpay->applied_at);

        // (D): Recompute period2 — adjustments already applied, should NOT be picked up again.
        $recomputed = $this->calc->computeForEmployee($period2, $emp);

        $this->assertSame('0.00', $recomputed->adjustment_amount,
            'Recompute must not double-apply already-applied adjustments');
        // Net on recomputed = gross - total_deductions + 0 adj
        $expectedNetRecomputed = \App\Common\Support\Money::sub(
            $recomputed->gross_pay,
            $recomputed->total_deductions,
        );
        $this->assertSame($expectedNetRecomputed, $recomputed->net_pay);
    }
}
