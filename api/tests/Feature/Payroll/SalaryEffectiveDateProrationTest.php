<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\SalaryAdjustmentService;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * HR-05 — a salary adjustment's effective_date must be authoritative for the
 * basic pay a period receives.
 *
 * apply() mutates the live salary columns at FULL APPROVAL, not at the
 * effective date. Two ways that used to overpay:
 *
 *   future-dated adjustment — a period computed after approval but ending
 *   before the effective date paid the already-updated live salary (raise
 *   paid early)
 *
 *   first history row inside a period — no older row to anchor the
 *   pre-effective days, so they fell back to the live (new) salary
 *
 * The segments path now pays the salary in effect on each day: future rows
 * hold the period at the pre-adjustment rate, and the pre-effective rate for
 * a first row is reconstructed from the creating adjustment's from_*
 * snapshot.
 */
class SalaryEffectiveDateProrationTest extends TestCase
{
    use RefreshDatabase;

    private PayrollCalculatorService $calc;
    private SalaryAdjustmentService $adjustments;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->calc = app(PayrollCalculatorService::class);
        $this->adjustments = app(SalaryAdjustmentService::class);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'pay_type'             => 'monthly',
            'basic_monthly_salary' => '20000.00',
            'date_hired'           => '2025-01-01',
            'status'               => 'active',
        ], $overrides));
    }

    private function period(string $start, string $end): PayrollPeriod
    {
        $period = PayrollPeriod::factory()->create([
            'period_start' => $start,
            'period_end'   => $end,
            'payroll_date' => $end,
            'is_first_half' => true,
        ]);
        $period->forceFill(['status' => 'draft'])->save();

        return $period->fresh();
    }

    /** Full 2-step approval chain (hr_officer requests, manager, admin). */
    private function approveRaise(Employee $employee, array $to, string $effectiveDate): void
    {
        $hr = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
        ]);
        $checker = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'production_manager')->value('id'),
        ]);
        $approver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $adjustment = $this->adjustments->request($employee, $to + [
            'effective_date' => $effectiveDate,
            'reason'         => 'Merit increase',
        ], $hr);

        $this->adjustments->approve($adjustment, $checker);
        $this->adjustments->approve($adjustment->fresh(), $approver);

        // apply() mutates the employee row through the adjustment's own model
        // instance; mirror the production caller (fresh DB load) so the
        // calculator sees the post-approval live columns.
        $employee->refresh();
    }

    // ─── Future-dated adjustment ─────────────────────────────

    public function test_a_period_ending_before_the_effective_date_pays_the_old_salary(): void
    {
        $employee = $this->makeEmployee();
        $this->approveRaise($employee, ['to_basic_monthly_salary' => '25000.00'], '2026-05-01');

        // The raise is already live (apply() runs at approval)…
        $this->assertSame('25000.00', (string) $employee->refresh()->basic_monthly_salary);

        // …but April's cutoff must still pay the April salary: 20000 / 2.
        $payroll = $this->calc->computeForEmployee($this->period('2026-04-01', '2026-04-15'), $employee);
        $this->assertSame('10000.00', $payroll->basic_pay);
    }

    public function test_the_period_covering_the_effective_date_pays_the_new_salary(): void
    {
        $employee = $this->makeEmployee();
        $this->approveRaise($employee, ['to_basic_monthly_salary' => '25000.00'], '2026-05-01');

        $payroll = $this->calc->computeForEmployee($this->period('2026-05-01', '2026-05-15'), $employee);

        $this->assertSame('12500.00', $payroll->basic_pay);
    }

    public function test_a_future_dated_semi_monthly_raise_holds_the_old_cutoff_rate(): void
    {
        $employee = $this->makeEmployee([
            'pay_type'             => 'semi_monthly',
            'semi_monthly_rate'    => '10000.00',
            'basic_monthly_salary' => null,
        ]);
        $this->approveRaise($employee, ['to_semi_monthly_rate' => '12000.00'], '2026-05-01');

        $payroll = $this->calc->computeForEmployee($this->period('2026-04-01', '2026-04-15'), $employee);

        // Old flat per-cutoff rate — not the live 12000 that apply() wrote.
        $this->assertSame('10000.00', $payroll->basic_pay);
    }

    // ─── First history row inside the period ─────────────────

    public function test_first_adjustment_mid_period_prorates_pre_effective_days_at_the_old_rate(): void
    {
        $employee = $this->makeEmployee();
        $this->approveRaise($employee, ['to_basic_monthly_salary' => '24000.00'], '2026-04-09');

        $payroll = $this->calc->computeForEmployee($this->period('2026-04-01', '2026-04-15'), $employee);

        // Apr 1–8 = 8 days at the reconstructed 20000 half-basic (10000);
        // Apr 9–15 = 7 days at 24000 half-basic (12000).
        // 10000 × 8/15 + 12000 × 7/15 = 5333.33 + 5599.99 = 10933.32
        // (bcdiv truncates each day-share at scale 6 before multiplying).
        $this->assertSame('10933.32', $payroll->basic_pay);
    }

    // ─── Effective date at/before the period start ───────────

    public function test_effective_date_on_period_start_pays_the_new_salary_flat(): void
    {
        $employee = $this->makeEmployee();
        $this->approveRaise($employee, ['to_basic_monthly_salary' => '25000.00'], '2026-04-01');

        $payroll = $this->calc->computeForEmployee($this->period('2026-04-01', '2026-04-15'), $employee);

        $this->assertSame('12500.00', $payroll->basic_pay);
    }

    public function test_effective_date_before_period_start_pays_the_new_salary_flat(): void
    {
        $employee = $this->makeEmployee();
        $this->approveRaise($employee, ['to_basic_monthly_salary' => '25000.00'], '2026-03-16');

        $payroll = $this->calc->computeForEmployee($this->period('2026-04-01', '2026-04-15'), $employee);

        $this->assertSame('12500.00', $payroll->basic_pay);
    }

    // ─── No history rows: legacy compatibility ───────────────

    public function test_an_employee_without_history_rows_still_pays_the_live_salary(): void
    {
        $employee = $this->makeEmployee();

        $payroll = $this->calc->computeForEmployee($this->period('2026-04-01', '2026-04-15'), $employee);

        $this->assertSame('10000.00', $payroll->basic_pay);
    }
}
