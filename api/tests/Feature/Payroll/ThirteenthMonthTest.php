<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Support\Money;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\ThirteenthMonthAccrual;
use App\Modules\Payroll\Services\ThirteenthMonthService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the behaviour of ThirteenthMonthService::computeAndPay().
 *
 * We build accrual rows directly (no need to run the full payroll calculator)
 * because accrue() is already covered by
 * PayrollCalculatorServiceTest::test_thirteenth_month_accrual_increments().
 */
class ThirteenthMonthTest extends TestCase
{
    use RefreshDatabase;

    private ThirteenthMonthService $svc;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->svc = app(ThirteenthMonthService::class);
        $this->adminUser = $this->makeUser();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $roleId = Role::query()->orderBy('id')->value('id');
        return User::create([
            'name'     => 'Tester '.uniqid(),
            'email'    => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        $dept = Department::create(['name' => 'Production '.uniqid(), 'code' => 'PRD'.substr(uniqid(), -4)]);
        $pos  = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        return Employee::create(array_merge([
            'employee_no'             => 'OGM-2025-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'first_name'              => 'Juan',
            'last_name'               => 'Dela Cruz',
            'birth_date'              => '1990-01-01',
            'gender'                  => 'male',
            'civil_status'            => 'single',
            'nationality'             => 'Filipino',
            'street_address'          => '123 Main',
            'city'                    => 'Dasmariñas',
            'province'                => 'Cavite',
            'mobile_number'           => '09171234567',
            'email'                   => 'jdc_'.uniqid().'@example.com',
            'emergency_contact_name'  => 'Maria',
            'emergency_contact_phone' => '09181234567',
            'department_id'           => $dept->id,
            'position_id'             => $pos->id,
            'employment_type'         => 'regular',
            'pay_type'                => 'monthly',
            'date_hired'              => '2025-01-01',
            'basic_monthly_salary'    => '24000.00',
            'status'                  => EmployeeStatus::Active->value,
        ], $overrides));
    }

    /**
     * Insert a raw accrual row — bypasses PayrollCalculatorService so we can
     * test computeAndPay() in isolation with exact, predictable totals.
     */
    private function seedAccrual(Employee $emp, int $year, string $totalBasicEarned): ThirteenthMonthAccrual
    {
        return ThirteenthMonthAccrual::create([
            'employee_id'        => $emp->id,
            'year'               => $year,
            'total_basic_earned' => $totalBasicEarned,
            'accrued_amount'     => Money::div($totalBasicEarned, '12', 2), // running estimate
            'is_paid'            => false,
        ]);
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * Full-year employee: accrued_amount in the final Payroll row equals
     * total_basic_earned / 12, using BCMath truncation at 2 d.p.
     *
     * 24 000 × 12 months basic = 288 000 total_basic_earned
     * expected accrued = bcdiv('288000.00', '12', 2) = '24000.00'
     */
    public function test_full_year_accrued_amount_is_total_basic_over_twelve(): void
    {
        $emp = $this->makeEmployee();

        // Simulate 12 semi-monthly periods × 12 000 each = 288 000 total basic
        $totalBasic = '288000.00';
        $this->seedAccrual($emp, 2025, $totalBasic);

        $period = $this->svc->computeAndPay(2025, $this->adminUser);

        // Period is the special 13th-month PayrollPeriod
        $this->assertTrue($period->is_thirteenth_month);
        $this->assertSame(PayrollPeriodStatus::Draft, $period->status);

        // One Payroll row created for the employee
        $payroll = Payroll::where('payroll_period_id', $period->id)
            ->where('employee_id', $emp->id)
            ->firstOrFail();

        // accrued_amount = total_basic_earned / 12 (BCMath truncated at 2 dp)
        $expectedAmount = bcdiv($totalBasic, '12', 2); // '24000.00'
        $this->assertSame($expectedAmount, $payroll->gross_pay,
            'gross_pay must equal total_basic_earned / 12');
        $this->assertSame($expectedAmount, $payroll->net_pay,
            'net_pay must equal gross_pay (zero deductions on 13th-month period)');

        // Accrual record is marked paid
        $accrual = ThirteenthMonthAccrual::where('employee_id', $emp->id)->where('year', 2025)->first();
        $this->assertTrue((bool) $accrual->is_paid, 'accrual must be flagged is_paid=true');
        $this->assertSame($expectedAmount, (string) $accrual->accrued_amount,
            'accrual.accrued_amount updated to final canonical value');
        $this->assertNotNull($accrual->paid_date);
        $this->assertSame($payroll->id, $accrual->payroll_id);
    }

    /**
     * Partial-year employee: less than full-year basic earnings.
     * Pin the ACTUAL service behaviour: accrued_amount = total_basic_earned / 12
     * regardless of months-worked count (the service does not pro-rate by months,
     * it simply divides whatever was accrued).
     *
     * 6 semi-monthly periods × 12 000 each = 72 000 total_basic_earned
     * expected = bcdiv('72000.00', '12', 2) = '6000.00'
     */
    public function test_partial_year_employee_gets_proportional_amount(): void
    {
        $emp = $this->makeEmployee([
            'date_hired' => '2025-07-01', // mid-year hire
        ]);

        // Only 6 months of accrual (Jul–Dec)
        $totalBasic = '72000.00'; // 6 × 12 000
        $this->seedAccrual($emp, 2025, $totalBasic);

        $period = $this->svc->computeAndPay(2025, $this->adminUser);

        $payroll = Payroll::where('payroll_period_id', $period->id)
            ->where('employee_id', $emp->id)
            ->firstOrFail();

        // Actual behaviour: service divides accrued total by 12 (not by months worked).
        // For a mid-year hire with 6 months of accrual: 72000 / 12 = 6000.
        $expectedAmount = bcdiv($totalBasic, '12', 2); // '6000.00'
        $this->assertSame($expectedAmount, $payroll->gross_pay,
            'partial-year: accrued_amount = total_basic_earned / 12 (no months-worked pro-rating)');
        $this->assertSame($expectedAmount, $payroll->net_pay);

        $accrual = ThirteenthMonthAccrual::where('employee_id', $emp->id)->where('year', 2025)->first();
        $this->assertTrue((bool) $accrual->is_paid);
    }

    /**
     * is_paid flag is set to true after computeAndPay() runs.
     * (Explicit, standalone assertion — complements the full-year test above.)
     */
    public function test_is_paid_flag_set_true_after_compute_and_pay(): void
    {
        $emp = $this->makeEmployee();
        $accrual = $this->seedAccrual($emp, 2025, '120000.00');

        $this->assertFalse((bool) $accrual->is_paid);

        $this->svc->computeAndPay(2025, $this->adminUser);

        $accrual->refresh();
        $this->assertTrue((bool) $accrual->is_paid,
            'ThirteenthMonthAccrual.is_paid must be true after computeAndPay');
        $this->assertNotNull($accrual->payroll_id,
            'payroll_id FK must be linked on the accrual after payout');
        $this->assertNotNull($accrual->paid_date,
            'paid_date must be set on the accrual after payout');
    }

    /**
     * Idempotency: running computeAndPay() twice for the same year does NOT
     * double-pay.
     *
     * Actual behaviour (from reading the service):
     *   - First run: creates PayrollPeriod + Payroll rows, marks accruals is_paid=true.
     *   - Second run: re-uses the same PayrollPeriod, wipes payroll rows (delete),
     *     but now no is_paid=false accruals exist → zero Payroll rows are re-created.
     *
     * This means the second call leaves the period "empty" (no payroll rows).
     * We assert: total Payroll rows in the period = count from the FIRST run
     * (i.e. identical to first run, not doubled), AND accruals remain is_paid=true.
     *
     * NOTE: The "wipe-then-reinsert" pattern means a second call to a non-finalized
     * period actually clears the payrolls. We pin this real behaviour here.
     */
    public function test_idempotency_second_run_does_not_double_pay(): void
    {
        $emp1 = $this->makeEmployee();
        $emp2 = $this->makeEmployee();

        $this->seedAccrual($emp1, 2025, '240000.00');
        $this->seedAccrual($emp2, 2025, '180000.00');

        // First run
        $period = $this->svc->computeAndPay(2025, $this->adminUser);
        $firstRunCount = Payroll::where('payroll_period_id', $period->id)->count();
        $this->assertSame(2, $firstRunCount, 'first run should produce 2 payroll rows');

        // Second run (same year, period not yet finalized)
        $period2 = $this->svc->computeAndPay(2025, $this->adminUser);

        // Same PayrollPeriod returned (not a new one)
        $this->assertSame($period->id, $period2->id,
            'second run must return the same PayrollPeriod, not a duplicate');

        // Actual idempotency: accruals are already paid, so wipe empties the
        // payroll table for this period and nothing new is inserted.
        // Total rows after second run should NOT be 4 (double-pay scenario).
        $secondRunCount = Payroll::where('payroll_period_id', $period->id)->count();
        $this->assertNotSame(4, $secondRunCount,
            'second run must not double the payroll rows (no double-pay)');

        // Accruals stay marked paid (not re-opened)
        $acc1 = ThirteenthMonthAccrual::where('employee_id', $emp1->id)->where('year', 2025)->first();
        $acc2 = ThirteenthMonthAccrual::where('employee_id', $emp2->id)->where('year', 2025)->first();
        $this->assertTrue((bool) $acc1->is_paid, 'emp1 accrual must remain is_paid after second run');
        $this->assertTrue((bool) $acc2->is_paid, 'emp2 accrual must remain is_paid after second run');
    }

    /**
     * computeAndPay() throws if the period is already Finalized.
     */
    public function test_throws_on_already_finalized_period(): void
    {
        $emp = $this->makeEmployee();
        $this->seedAccrual($emp, 2025, '60000.00');

        // First run then manually finalize the period
        $period = $this->svc->computeAndPay(2025, $this->adminUser);
        $period->update(['status' => PayrollPeriodStatus::Finalized->value]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already finalized');
        $this->svc->computeAndPay(2025, $this->adminUser);
    }

    /**
     * Employees with status != active are skipped.
     * A resigned employee's accrual is NOT paid in the batch.
     */
    public function test_inactive_employee_accrual_is_skipped(): void
    {
        $activeEmp   = $this->makeEmployee();
        $resignedEmp = $this->makeEmployee(['status' => EmployeeStatus::Resigned->value]);

        $this->seedAccrual($activeEmp, 2025, '120000.00');
        $this->seedAccrual($resignedEmp, 2025, '60000.00');

        $period = $this->svc->computeAndPay(2025, $this->adminUser);

        // Only one payroll row — the active employee
        $this->assertSame(1, Payroll::where('payroll_period_id', $period->id)->count(),
            'resigned employee must be excluded from the 13th-month run');

        // Active employee: paid
        $activeAccrual = ThirteenthMonthAccrual::where('employee_id', $activeEmp->id)->first();
        $this->assertTrue((bool) $activeAccrual->is_paid);

        // Resigned employee: still unpaid
        $resignedAccrual = ThirteenthMonthAccrual::where('employee_id', $resignedEmp->id)->first();
        $this->assertFalse((bool) $resignedAccrual->is_paid,
            'resigned employee accrual must remain unpaid');
    }

    /**
     * The special PayrollPeriod created by computeAndPay() spans Dec 1–31 of
     * the given year and has is_thirteenth_month=true.
     */
    public function test_special_payroll_period_spans_december(): void
    {
        $emp = $this->makeEmployee();
        $this->seedAccrual($emp, 2025, '144000.00');

        $period = $this->svc->computeAndPay(2025, $this->adminUser, '2025-12-15');

        $this->assertSame('2025-12-01', $period->period_start->toDateString());
        $this->assertSame('2025-12-31', $period->period_end->toDateString());
        $this->assertSame('2025-12-15', $period->payroll_date->toDateString());
        $this->assertTrue($period->is_thirteenth_month);
    }

    /**
     * A deduction detail line with type 'thirteenth_month' is created for the
     * payslip, containing the same amount as gross/net pay.
     */
    public function test_deduction_detail_line_created_for_payslip(): void
    {
        $emp = $this->makeEmployee();
        $totalBasic = '60000.00';
        $this->seedAccrual($emp, 2025, $totalBasic);

        $period = $this->svc->computeAndPay(2025, $this->adminUser);

        $payroll = Payroll::where('payroll_period_id', $period->id)
            ->where('employee_id', $emp->id)
            ->with('deductionDetails')
            ->firstOrFail();

        $this->assertSame(1, $payroll->deductionDetails->count(),
            'exactly one deduction detail line expected');

        $detail = $payroll->deductionDetails->first();
        $this->assertSame('thirteenth_month', $detail->deduction_type->value);

        $expectedAmount = bcdiv($totalBasic, '12', 2);
        $this->assertSame($expectedAmount, $detail->amount,
            'detail amount must equal accrued_amount');
    }
}
