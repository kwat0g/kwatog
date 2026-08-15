<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\ThirteenthMonthAccrual;
use App\Modules\Payroll\Services\ThirteenthMonthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P01-01 shape on the 13th-month accrual (P82-adjacent, money): accrue() ran an
 * unlocked `firstOrCreate` + read-modify-write on `total_basic_earned` with no
 * unique index on (employee_id, year) — two concurrent accruals for the same
 * employee could double-create the accrual row and split/lose a period's
 * contribution. The fix serializes on the employee row; this invariant pins the
 * single-row, full-sum behaviour for two periods accrued back to back.
 */
class ThirteenthMonthAccrualInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_two_periods_accumulate_into_single_accrual_row(): void
    {
        $employee = Employee::factory()->create();

        $periodA = PayrollPeriod::factory()->create([
            'period_start' => '2026-01-01',
            'period_end'   => '2026-01-15',
        ]);
        $periodB = PayrollPeriod::factory()->create([
            'period_start' => '2026-02-01',
            'period_end'   => '2026-02-15',
        ]);
        $payrollA = Payroll::factory()->create([
            'payroll_period_id' => $periodA->id,
            'employee_id'       => $employee->id,
            'pay_type'          => 'semi_monthly',
            'basic_pay'         => '10000.00',
        ]);
        $payrollB = Payroll::factory()->create([
            'payroll_period_id' => $periodB->id,
            'employee_id'       => $employee->id,
            'pay_type'          => 'semi_monthly',
            'basic_pay'         => '20000.00',
        ]);

        $svc = app(ThirteenthMonthService::class);
        $svc->accrue(Payroll::find($payrollA->id));
        $svc->accrue(Payroll::find($payrollB->id));

        $rows = ThirteenthMonthAccrual::query()
            ->where('employee_id', $employee->id)
            ->where('year', 2026)
            ->get();

        $this->assertCount(1, $rows, 'Both accruals must land in one accrual row per employee/year.');
        $this->assertSame('30000.00', $rows->first()->total_basic_earned);
        $this->assertSame('2500.00', $rows->first()->accrued_amount); // 30000 / 12
    }
}
