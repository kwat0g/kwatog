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
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
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
        $userId = \App\Modules\Auth\Models\User::create([
            'name' => 'Tester',
            'email' => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
        ])->id;

        $start = $start ?? '2026-04-01';
        $end   = $end   ?? '2026-04-15';

        return PayrollPeriod::create([
            'period_start'  => $start,
            'period_end'    => $end,
            'payroll_date'  => $end,
            'is_first_half' => $firstHalf,
            'is_thirteenth_month' => false,
            'status'        => PayrollPeriodStatus::Draft->value,
            'created_by'    => $userId,
        ]);
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
            'status'               => LoanStatus::Active->value,
            'start_date'           => '2026-04-01',
        ]);

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
        $period->update(['status' => PayrollPeriodStatus::Finalized->value]);

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

        EmployeeLoan::create([
            'loan_no' => 'LN-X', 'employee_id' => $emp->id,
            'loan_type' => LoanType::CashAdvance->value,
            'principal' => '5000.00', 'monthly_amortization' => '5000.00',
            'total_paid' => '0.00', 'balance' => '5000.00',
            'pay_periods_total' => 1, 'pay_periods_remaining' => 1,
            'status' => LoanStatus::Active->value, 'start_date' => '2026-04-01',
        ]);

        $payroll = $this->calc->computeForEmployee($period, $emp);

        $this->assertSame('0.00', $payroll->net_pay);
    }
}
