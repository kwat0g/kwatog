<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Jobs\ProcessYearEndLeave;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Models\YearEndLeaveDisposition;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Enums\PayrollAdjustmentType;
use App\Modules\Payroll\Models\PayrollAdjustment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * REC-10 — year-end leave: single-source-of-truth disposition, paid encashment,
 * and Reset consuming the disposition (no double-handling).
 */
class YearEndLeaveReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function systemUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function balance(Employee $emp, LeaveType $lt, int $year, float $remaining, float $credits): void
    {
        EmployeeLeaveBalance::create([
            'employee_id' => $emp->id, 'leave_type_id' => $lt->id, 'year' => $year,
            'total_credits' => $credits, 'used' => $credits - $remaining, 'remaining' => $remaining,
        ]);
    }

    public function test_convertible_type_encashes_and_pays_via_adjustment(): void
    {
        $emp = Employee::factory()->create([
            'pay_type' => 'monthly', 'basic_monthly_salary' => '22000.00', 'semi_monthly_rate' => null,
        ]); // daily rate = 22000/22 = 1000.00
        $lt = LeaveType::create([
            'name' => 'Vacation Leave', 'code' => 'VL-'.substr(uniqid(), -5),
            'default_balance' => 10.0, 'is_paid' => true, 'is_active' => true,
            'is_convertible_year_end' => true, 'conversion_rate' => 0.80,
        ]);
        $this->balance($emp, $lt, 2025, remaining: 10.0, credits: 10.0);

        (new ProcessYearEndLeave($this->systemUser(), 2025))->handle();

        // 10 remaining × 0.8 = 8 converted; 2 forfeited; balance zeroed.
        $bal = EmployeeLeaveBalance::where(['employee_id' => $emp->id, 'leave_type_id' => $lt->id, 'year' => 2025])->first();
        $this->assertSame('0.0', (string) $bal->remaining);

        $disp = YearEndLeaveDisposition::where(['employee_id' => $emp->id, 'leave_type_id' => $lt->id, 'year' => 2025])->first();
        $this->assertNotNull($disp);
        $this->assertSame('8.0', (string) $disp->days_converted);
        $this->assertSame('0.0', (string) $disp->days_carried);
        $this->assertSame('2.0', (string) $disp->days_forfeited);
        // cash = 8 days × 1000/day = 8000.00
        $this->assertSame('8000.00', (string) $disp->cash_value);

        // An approved, unapplied Underpayment adjustment now exists.
        $adj = PayrollAdjustment::where('employee_id', $emp->id)->first();
        $this->assertNotNull($adj);
        $this->assertSame(PayrollAdjustmentType::Underpayment, $adj->type);
        $this->assertSame(PayrollAdjustmentStatus::Approved, $adj->status);
        $this->assertSame('8000.00', (string) $adj->amount);
        $this->assertNull($adj->payroll_period_id);
        $this->assertNull($adj->original_payroll_id);
        $this->assertNull($adj->applied_at);
        $this->assertSame((int) $adj->id, (int) $disp->payroll_adjustment_id);
    }

    public function test_non_convertible_type_carries_up_to_cap_and_forfeits_excess(): void
    {
        $emp = Employee::factory()->create(['pay_type' => 'monthly', 'basic_monthly_salary' => '22000.00']);
        $lt = LeaveType::create([
            'name' => 'Sick Leave', 'code' => 'SL-'.substr(uniqid(), -5),
            'default_balance' => 10.0, 'is_paid' => true, 'is_active' => true,
            'is_convertible_year_end' => false, 'conversion_rate' => 1.00,
            'max_carryover_days' => 5.0,
        ]);
        $this->balance($emp, $lt, 2025, remaining: 8.0, credits: 10.0);

        (new ProcessYearEndLeave($this->systemUser(), 2025))->handle();

        $disp = YearEndLeaveDisposition::where(['employee_id' => $emp->id, 'leave_type_id' => $lt->id, 'year' => 2025])->first();
        $this->assertSame('5.0', (string) $disp->days_carried);   // min(8, cap 5)
        $this->assertSame('3.0', (string) $disp->days_forfeited); // excess
        $this->assertSame('0.0', (string) $disp->days_converted);
        $this->assertSame('0.00', (string) $disp->cash_value);
        // Non-convertible → no encashment adjustment.
        $this->assertSame(0, PayrollAdjustment::where('employee_id', $emp->id)->count());
    }

    public function test_reset_consumes_disposition_without_double_counting(): void
    {
        $emp = Employee::factory()->create(['pay_type' => 'monthly', 'basic_monthly_salary' => '22000.00']);
        $lt = LeaveType::create([
            'name' => 'Sick Leave', 'code' => 'SL-'.substr(uniqid(), -5),
            'default_balance' => 10.0, 'is_paid' => true, 'is_active' => true,
            'is_convertible_year_end' => false, 'conversion_rate' => 1.00,
            'max_carryover_days' => 5.0,
        ]);
        $this->balance($emp, $lt, 2025, remaining: 8.0, credits: 10.0);

        (new ProcessYearEndLeave($this->systemUser(), 2025))->handle();
        Artisan::call('hr:reset-leave-balances', ['--year' => 2026]);

        // New-year balance = default_balance (10) + days_carried (5) = 15. NOT
        // re-reading the raw remaining (which the job zeroed) or re-applying rate.
        $new = EmployeeLeaveBalance::where(['employee_id' => $emp->id, 'leave_type_id' => $lt->id, 'year' => 2026])->first();
        $this->assertNotNull($new);
        $this->assertSame('15.0', (string) $new->total_credits);
        $this->assertSame('15.0', (string) $new->remaining);
    }

    public function test_idempotent_second_run_does_not_double_pay(): void
    {
        $emp = Employee::factory()->create(['pay_type' => 'monthly', 'basic_monthly_salary' => '22000.00']);
        $lt = LeaveType::create([
            'name' => 'Vacation Leave', 'code' => 'VL-'.substr(uniqid(), -5),
            'default_balance' => 10.0, 'is_paid' => true, 'is_active' => true,
            'is_convertible_year_end' => true, 'conversion_rate' => 1.00,
        ]);
        $this->balance($emp, $lt, 2025, remaining: 5.0, credits: 10.0);

        (new ProcessYearEndLeave($this->systemUser(), 2025))->handle();
        (new ProcessYearEndLeave($this->systemUser(), 2025))->handle();

        // Only one adjustment + one disposition despite two runs.
        $this->assertSame(1, PayrollAdjustment::where('employee_id', $emp->id)->count());
        $this->assertSame(1, YearEndLeaveDisposition::where('employee_id', $emp->id)->count());
    }

    public function test_reset_without_disposition_fails_closed_without_mutating_balances(): void
    {
        $emp = Employee::factory()->create(['pay_type' => 'monthly', 'basic_monthly_salary' => '22000.00']);
        $lt = LeaveType::create([
            'name' => 'Vacation Leave', 'code' => 'VL-'.substr(uniqid(), -5),
            'default_balance' => 10.0, 'is_paid' => true, 'is_active' => true,
            'is_convertible_year_end' => false, 'conversion_rate' => 1.00,
        ]);
        // Prior-year balance exists but year-end job NEVER ran (no disposition).
        $this->balance($emp, $lt, 2025, remaining: 4.0, credits: 10.0);

        $exitCode = Artisan::call('hr:reset-leave-balances', ['--year' => 2026]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseMissing('employee_leave_balances', [
            'employee_id' => $emp->id,
            'leave_type_id' => $lt->id,
            'year' => 2026,
        ]);
    }
}
