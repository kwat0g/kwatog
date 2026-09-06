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

/**
 * LN-03 — is_final_pay_deduction finally decides something: it reserves the
 * loan for settlement from final pay, so ordinary payroll amortization must
 * skip it or the same balance gets collected twice during a separation.
 */
class LoanFinalPayReservationFlagTest extends TestCase
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

    private function makeEmployee(): Employee
    {
        $dept = Department::create(['name' => 'Production', 'code' => 'PRD']);
        $pos  = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        return Employee::create([
            'employee_no'          => 'OGM-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'first_name'           => 'Juan',
            'last_name'            => 'Dela Cruz',
            'birth_date'           => '1990-01-01',
            'gender'               => 'male',
            'civil_status'         => 'single',
            'nationality'          => 'Filipino',
            'department_id'        => $dept->id,
            'position_id'          => $pos->id,
            'employment_type'      => 'regular',
            'pay_type'             => 'monthly',
            'date_hired'           => '2025-01-01',
            'basic_monthly_salary' => '20000.00',
            'status'               => 'active',
        ]);
    }

    private function makePeriod(): PayrollPeriod
    {
        $roleId = \App\Modules\Auth\Models\Role::query()->orderBy('id')->value('id');
        $userId = \App\Modules\Auth\Models\User::create([
            'name'     => 'Tester',
            'email'    => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ])->id;

        $period = PayrollPeriod::create([
            'period_start'        => '2026-04-01',
            'period_end'          => '2026-04-15',
            'payroll_date'        => '2026-04-15',
            'is_first_half'       => true,
            'is_thirteenth_month' => false,
            'created_by'          => $userId,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();
        return $period;
    }

    private function attendanceFor(Employee $emp): void
    {
        $cur = \Carbon\Carbon::parse('2026-04-01');
        $end = \Carbon\Carbon::parse('2026-04-15');
        while ($cur->lte($end)) {
            if ($cur->dayOfWeek !== 0) {
                Attendance::create([
                    'employee_id'       => $emp->id,
                    'date'              => $cur->toDateString(),
                    'time_in'           => $cur->copy()->setTime(8, 0)->toDateTimeString(),
                    'time_out'          => $cur->copy()->setTime(16, 0)->toDateTimeString(),
                    'regular_hours'     => 8.0,
                    'overtime_hours'    => 0,
                    'night_diff_hours'  => 0,
                    'tardiness_minutes' => 0,
                    'undertime_minutes' => 0,
                    'is_rest_day'       => false,
                    'day_type_rate'     => 1.00,
                    'status'            => 'present',
                ]);
            }
            $cur->addDay();
        }
    }

    private function makeLoan(Employee $emp, bool $reserved): EmployeeLoan
    {
        $loan = EmployeeLoan::create([
            'loan_no'                => 'LN-'.($reserved ? 'RESERVED' : 'ORDINARY').'-'.random_int(1000, 9999),
            'employee_id'            => $emp->id,
            'loan_type'              => LoanType::CompanyLoan->value,
            'principal'              => '6000.00',
            'monthly_amortization'   => '1000.00',
            'total_paid'             => '0.00',
            'balance'                => '6000.00',
            'pay_periods_total'      => 12,
            'pay_periods_remaining'  => 12,
            'start_date'             => '2026-04-01',
            'is_final_pay_deduction' => $reserved,
        ]);
        $loan->forceFill(['status' => LoanStatus::Active->value])->save();
        return $loan;
    }

    public function test_flagged_loan_is_skipped_by_payroll_amortization(): void
    {
        $emp    = $this->makeEmployee();
        $period = $this->makePeriod();
        $this->attendanceFor($emp);
        $loan = $this->makeLoan($emp, true);

        $payroll = $this->calc->computeForEmployee($period, $emp);

        $this->assertSame('0.00', $payroll->loan_deductions, 'A loan reserved for final pay must not be amortized by payroll.');
        $this->assertSame(0, $loan->payments()->count());
        $loan->refresh();
        $this->assertSame('6000.00', (string) $loan->balance);
        $this->assertSame(12, $loan->pay_periods_remaining);
    }

    public function test_unflagged_loan_is_still_amortized(): void
    {
        $emp    = $this->makeEmployee();
        $period = $this->makePeriod();
        $this->attendanceFor($emp);
        $loan = $this->makeLoan($emp, false);

        $payroll = $this->calc->computeForEmployee($period, $emp);

        $this->assertSame('500.00', $payroll->loan_deductions, 'An ordinary loan keeps its half-amortization per semi-monthly period.');
        $this->assertSame(1, $loan->payments()->count());
        $this->assertSame('5500.00', (string) $loan->fresh()->balance);
    }
}
