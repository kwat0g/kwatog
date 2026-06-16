<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSalaryHistory;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MidCycleSalaryProrationTest extends TestCase
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
        $dept = Department::create(['name' => 'Production', 'code' => 'PRD'.random_int(1, 9999)]);
        $pos  = Position::create(['title' => 'Operator', 'department_id' => $dept->id]);
        return Employee::create(array_merge([
            'employee_no'          => 'OGM-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'first_name'           => 'Juan', 'last_name' => 'Dela Cruz',
            'birth_date'           => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'nationality'          => 'Filipino', 'street_address' => '123 Main',
            'city'                 => 'Dasmariñas', 'province' => 'Cavite',
            'mobile_number'        => '09171234567', 'email' => 'jdc'.random_int(1, 9999).'@example.com',
            'emergency_contact_name'  => 'Maria', 'emergency_contact_phone' => '09181234567',
            'department_id'        => $dept->id, 'position_id' => $pos->id,
            'employment_type'      => 'regular', 'pay_type' => 'monthly',
            'date_hired'           => '2025-01-01', 'basic_monthly_salary' => '20000.00',
            'status'               => 'active',
        ], $overrides));
    }

    private function makePeriod(string $start = '2026-04-01', string $end = '2026-04-15'): PayrollPeriod
    {
        $roleId = Role::query()->orderBy('id')->value('id');
        $userId = User::create([
            'name' => 'Tester', 'email' => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $roleId,
        ])->id;

        $period = PayrollPeriod::create([
            'period_start' => $start, 'period_end' => $end, 'payroll_date' => $end,
            'is_first_half' => true, 'is_thirteenth_month' => false, 'created_by' => $userId,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();
        return $period;
    }

    private function attendance(Employee $emp, string $start, string $end): void
    {
        $cur = \Carbon\Carbon::parse($start);
        $endC = \Carbon\Carbon::parse($end);
        while ($cur->lte($endC)) {
            if ($cur->dayOfWeek !== 0) {
                Attendance::create([
                    'employee_id' => $emp->id, 'date' => $cur->toDateString(),
                    'time_in' => $cur->copy()->setTime(8, 0)->toDateTimeString(),
                    'time_out' => $cur->copy()->setTime(17, 0)->toDateTimeString(),
                    'regular_hours' => 8, 'overtime_hours' => 0, 'night_diff_hours' => 0,
                    'tardiness_minutes' => 0, 'undertime_minutes' => 0,
                    'is_rest_day' => false, 'day_type_rate' => 1.00, 'status' => 'present',
                ]);
            }
            $cur->addDay();
        }
    }

    public function test_no_history_behaves_exactly_as_legacy_monthly(): void
    {
        $emp = $this->makeEmployee(['basic_monthly_salary' => '20000.00']);
        $period = $this->makePeriod();
        $this->attendance($emp, '2026-04-01', '2026-04-15');

        $payroll = $this->calc->computeForEmployee($period, $emp);

        // Legacy: half-month basic = 20000 / 2 = 10000.00 (no history rows).
        $this->assertSame('10000.00', $payroll->basic_pay);
    }

    public function test_no_history_behaves_exactly_as_legacy_daily(): void
    {
        $emp = $this->makeEmployee(['pay_type' => 'daily', 'daily_rate' => '1000.00', 'basic_monthly_salary' => '0.00']);
        $period = $this->makePeriod();
        $this->attendance($emp, '2026-04-01', '2026-04-15'); // 13 worked days (Sundays skipped: Apr 5, 12)

        $payroll = $this->calc->computeForEmployee($period, $emp);

        $expectedDays = (float) $payroll->days_worked;
        $this->assertSame(number_format($expectedDays * 1000, 2, '.', ''), $payroll->basic_pay);
    }

    public function test_monthly_raise_mid_period_is_prorated(): void
    {
        $emp = $this->makeEmployee(['basic_monthly_salary' => '24000.00']);
        $period = $this->makePeriod('2026-04-01', '2026-04-15'); // 15 calendar days
        $this->attendance($emp, '2026-04-01', '2026-04-15');

        // Starting salary 20000 on hire; raise to 24000 effective Apr 9.
        EmployeeSalaryHistory::create([
            'employee_id' => $emp->id, 'basic_monthly_salary' => '20000.00',
            'effective_date' => '2025-01-01',
        ]);
        EmployeeSalaryHistory::create([
            'employee_id' => $emp->id, 'basic_monthly_salary' => '24000.00',
            'effective_date' => '2026-04-09',
        ]);

        $payroll = $this->calc->computeForEmployee($period, $emp);

        // Apr 1-8 = 8 days @ 20000 half-basic (10000); Apr 9-15 = 7 days @ 24000 half-basic (12000).
        // 10000 * 8/15 + 12000 * 7/15 = 5333.333... + 5600 = 10933.33
        $expected = bcadd(
            bcmul('10000', bcdiv('8', '15', 6), 6),
            bcmul('12000', bcdiv('7', '15', 6), 6),
            6
        );
        $this->assertSame(number_format((float) $expected, 2, '.', ''), $payroll->basic_pay);
        // Sanity: prorated value sits between the two flat half-basics.
        $this->assertGreaterThan(10000.0, (float) $payroll->basic_pay);
        $this->assertLessThan(12000.0, (float) $payroll->basic_pay);
    }

    public function test_history_with_no_change_inside_period_uses_legacy_path(): void
    {
        $emp = $this->makeEmployee(['basic_monthly_salary' => '20000.00']);
        $period = $this->makePeriod('2026-04-01', '2026-04-15');
        $this->attendance($emp, '2026-04-01', '2026-04-15');

        // A history row exists but its effective date is BEFORE the period — no
        // mid-period change, so legacy half-basic applies.
        EmployeeSalaryHistory::create([
            'employee_id' => $emp->id, 'basic_monthly_salary' => '20000.00',
            'effective_date' => '2025-06-01',
        ]);

        $payroll = $this->calc->computeForEmployee($period, $emp);
        $this->assertSame('10000.00', $payroll->basic_pay);
    }
}
