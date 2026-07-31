<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Enums\PayrollAdjustmentType;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\ThirteenthMonthAccrual;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Recompute must be idempotent with respect to money.
 *
 * Recomputing a period deletes and rebuilds its payroll rows. Three separate
 * defects made that destructive, and all three were reachable simply by clicking
 * the Compute button twice (which the UI allowed indefinitely):
 *
 *   1. loan_payments were bulk-deleted BEFORE reverseLoanDeductions() read them,
 *      so loan balances were never restored while the new run deducted the
 *      amortization again.
 *   2. Adjustments applied by the previous run kept applied_at set, so the
 *      re-run's `whereNull('applied_at')` filter skipped them and the employee
 *      silently lost the money.
 *   3. The 13th-month accrual is an additive running total, so each recompute
 *      added another half-month of basic pay to the employee's 13th-month base.
 */
class PayrollRecomputeIntegrityTest extends TestCase
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

    private function makePeriod(): PayrollPeriod
    {
        $userId = User::factory()->create([
            'role_id' => Role::query()->orderBy('id')->value('id'),
        ])->id;

        $period = PayrollPeriod::create([
            'period_start'         => '2026-04-01',
            'period_end'           => '2026-04-15',
            'payroll_date'         => '2026-04-15',
            'is_first_half'        => true,
            'is_thirteenth_month'  => false,
            'created_by'           => $userId,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

        return $period;
    }

    private function attend(Employee $emp, string $date, int $tardyMins = 0): void
    {
        Attendance::create([
            'employee_id'       => $emp->id,
            'date'             => $date,
            'time_in'          => $date.' 08:00:00',
            'time_out'         => $date.' 17:00:00',
            'regular_hours'    => 8,
            'overtime_hours'   => 0,
            'night_diff_hours' => 0,
            'tardiness_minutes' => $tardyMins,
            'undertime_minutes' => 0,
            'is_rest_day'      => false,
            'day_type_rate'    => 1.00,
            'status'           => 'present',
        ]);
    }

    public function test_recompute_does_not_double_deduct_a_loan(): void
    {
        $emp    = $this->makeEmployee();
        $period = $this->makePeriod();
        $this->attend($emp, '2026-04-01');

        $loan = EmployeeLoan::create([
            'employee_id'           => $emp->id,
            'loan_no'               => 'LN-T-'.substr(uniqid(), -5),
            'loan_type'             => LoanType::CompanyLoan->value,
            'principal'             => '12000.00',
            'pay_periods_total'     => 12,
            'monthly_amortization'  => '2000.00',
            'balance'               => '12000.00',
            'total_paid'            => '0.00',
            'pay_periods_remaining' => 12,
            'start_date'            => '2026-01-01',
        ]);
        $loan->forceFill(['status' => LoanStatus::Active->value])->save();

        $this->calc->computeForEmployee($period, $emp);

        $afterFirst = $loan->fresh();
        $balanceAfterOneRun = (string) $afterFirst->balance;
        // Semi-monthly = half the monthly amortization.
        $this->assertSame('11000.00', $balanceAfterOneRun);
        $this->assertSame('1000.00', (string) $afterFirst->total_paid);
        $this->assertSame(11, $afterFirst->pay_periods_remaining);

        // Recompute the SAME period. The loan must land in exactly the same
        // state — one period deducted once, not twice.
        $this->calc->computeForEmployee($period, $emp);

        $afterSecond = $loan->fresh();
        $this->assertSame(
            $balanceAfterOneRun,
            (string) $afterSecond->balance,
            'Recompute must not deduct the loan a second time',
        );
        $this->assertSame('1000.00', (string) $afterSecond->total_paid);
        $this->assertSame(11, $afterSecond->pay_periods_remaining);

        // Exactly one payment trace row survives.
        $this->assertSame(1, LoanPayment::where('loan_id', $loan->id)->count());
    }

    public function test_recompute_reapplies_an_adjustment_instead_of_dropping_it(): void
    {
        $emp    = $this->makeEmployee();
        $period = $this->makePeriod();
        $this->attend($emp, '2026-04-01');

        // An adjustment needs a finalized origin payroll to hang off, so build a
        // prior period, finalize it, and raise the adjustment against it.
        $priorPeriod = PayrollPeriod::create([
            'period_start' => '2026-03-01', 'period_end' => '2026-03-15',
            'payroll_date' => '2026-03-15', 'is_first_half' => true,
            'is_thirteenth_month' => false, 'created_by' => $period->created_by,
        ]);
        $priorPeriod->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();
        $this->attend($emp, '2026-03-02');
        $priorPayroll = $this->calc->computeForEmployee($priorPeriod, $emp);
        $priorPeriod->forceFill(['status' => PayrollPeriodStatus::Finalized->value])->save();

        $adj = PayrollAdjustment::create([
            'payroll_period_id'   => $priorPeriod->id,
            'employee_id'         => $emp->id,
            'original_payroll_id' => $priorPayroll->id,
            'type'                => PayrollAdjustmentType::Underpayment->value,
            'amount'              => '1500.00',
            'reason'              => 'Missed OT on Mar 10',
            'created_by'          => $period->created_by,
        ]);
        $adj->forceFill(['status' => PayrollAdjustmentStatus::Approved->value])->save();

        $first = $this->calc->computeForEmployee($period, $emp);
        $this->assertSame('1500.00', (string) $first->adjustment_amount);
        $this->assertSame(PayrollAdjustmentStatus::Applied, $adj->fresh()->status);

        // Recompute. The adjustment must still be reflected in take-home pay —
        // previously it was skipped and the employee silently lost ₱1,500.
        $second = $this->calc->computeForEmployee($period, $emp);

        $this->assertSame(
            '1500.00',
            (string) $second->adjustment_amount,
            'Recompute must re-apply the adjustment, not drop it',
        );
        $this->assertSame((string) $first->net_pay, (string) $second->net_pay);

        $adjFresh = $adj->fresh();
        $this->assertSame(PayrollAdjustmentStatus::Applied, $adjFresh->status);
        $this->assertSame($second->id, $adjFresh->applied_to_payroll_id);
    }

    public function test_recompute_does_not_double_accrue_thirteenth_month(): void
    {
        $emp    = $this->makeEmployee();
        $period = $this->makePeriod();
        $this->attend($emp, '2026-04-01');

        $first = $this->calc->computeForEmployee($period, $emp);

        $accrual = ThirteenthMonthAccrual::where('employee_id', $emp->id)
            ->where('year', 2026)
            ->firstOrFail();
        $baseAfterOneRun = (string) $accrual->total_basic_earned;
        $this->assertSame((string) $first->basic_pay, $baseAfterOneRun);

        $this->calc->computeForEmployee($period, $emp);
        $this->calc->computeForEmployee($period, $emp);

        $this->assertSame(
            $baseAfterOneRun,
            (string) $accrual->fresh()->total_basic_earned,
            '13th-month base must not grow when the same period is recomputed',
        );
    }

    /** Money already paid out must be immutable. */
    public function test_disbursed_period_cannot_be_recomputed(): void
    {
        $emp    = $this->makeEmployee();
        $period = $this->makePeriod();
        $this->attend($emp, '2026-04-01');
        $this->calc->computeForEmployee($period, $emp);

        $period->forceFill(['status' => PayrollPeriodStatus::Disbursed->value])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot recompute: payroll period is disbursed.');
        $this->calc->computeForEmployee($period->fresh(), $emp);
    }

    public function test_voided_period_cannot_be_recomputed(): void
    {
        $emp    = $this->makeEmployee();
        $period = $this->makePeriod();
        $this->attend($emp, '2026-04-01');
        $this->calc->computeForEmployee($period, $emp);

        $period->forceFill(['status' => PayrollPeriodStatus::Voided->value])->save();

        $this->expectException(\RuntimeException::class);
        $this->calc->computeForEmployee($period->fresh(), $emp);
    }

    /** Tardiness must be persisted so the GL can balance and the payslip can show it. */
    public function test_tardiness_and_undertime_are_persisted(): void
    {
        $emp    = $this->makeEmployee();
        $period = $this->makePeriod();
        $this->attend($emp, '2026-04-01', tardyMins: 60);

        $payroll = $this->calc->computeForEmployee($period, $emp);

        // 20000/22/8 = 113.6364/hr, one hour late.
        $this->assertTrue(
            (float) $payroll->tardiness_deduction > 0,
            'Tardiness must be stored on the payroll row',
        );
        // Gross reflects the deduction.
        $earnings = (float) $payroll->basic_pay + (float) $payroll->leave_pay
            + (float) $payroll->overtime_pay + (float) $payroll->night_diff_pay
            + (float) $payroll->holiday_pay;
        $this->assertEqualsWithDelta(
            $earnings - (float) $payroll->tardiness_deduction - (float) $payroll->undertime_deduction,
            (float) $payroll->gross_pay,
            0.01,
        );
    }
}
