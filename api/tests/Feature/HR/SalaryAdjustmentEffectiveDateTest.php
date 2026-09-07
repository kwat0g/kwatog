<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HR-05 — salary adjustments must honor their effective date.
 *
 * apply() used to flip the LIVE pay columns at approval time, so any period
 * computed afterwards — even one ending before the effective date — paid the
 * raise early off the live salary. And when an employee's FIRST history row
 * landed strictly inside a period, the pre-effective days fell back to the
 * live (already-new) salary and were overpaid.
 *
 * The history row is written at approval regardless; the live row only moves
 * when the effective date has arrived (immediately, or deferred via
 * hr:apply-due-salary-adjustments), and payroll's segments read the
 * from-values of the first history row for the pre-change days.
 */
class SalaryAdjustmentEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    private SalaryAdjustmentService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RolePermissionSeeder::class, WorkflowSeeder::class, GovernmentTableSeeder::class]);
        $this->svc = app(SalaryAdjustmentService::class);
    }

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'pay_type' => 'monthly',
            'basic_monthly_salary' => '20000.00',
            'date_hired' => '2024-01-01',
        ], $overrides));
    }

    private function approveRaise(Employee $employee, string $toSalary, string $effectiveDate): void
    {
        $adjustment = $this->svc->request($employee, [
            'to_basic_monthly_salary' => $toSalary,
            'effective_date' => $effectiveDate,
            'reason' => 'Merit',
        ], $this->user('hr_officer'));

        $this->svc->approve($adjustment, $this->user('production_manager'));
        $this->svc->approve($adjustment, $this->user('system_admin'));
    }

    private function makePeriod(string $start, string $end, bool $isFirstHalf): PayrollPeriod
    {
        $period = PayrollPeriod::factory()->create([
            'period_start' => $start,
            'period_end' => $end,
            'payroll_date' => $end,
            'is_first_half' => $isFirstHalf,
        ]);
        $period->forceFill(['status' => 'draft'])->save();

        return $period->fresh();
    }

    // ─── Live-row deferral at approval ───────────────────────────

    public function test_future_effective_date_defers_the_live_salary_update(): void
    {
        $employee = $this->makeEmployee();
        $effective = now()->addDays(10)->toDateString();

        $this->approveRaise($employee, '25000.00', $effective);

        $adjustment = DB::table('salary_adjustments')->where('employee_id', $employee->id)->first();
        $this->assertNotNull($adjustment->applied_at, 'Approval must apply the history row immediately.');
        $this->assertNull($adjustment->live_applied_at, 'A future-dated raise must not touch the live row yet.');
        $this->assertSame('20000.00', (string) $employee->fresh()->basic_monthly_salary);
        $this->assertDatabaseHas('employee_salary_history', [
            'employee_id' => $employee->id,
            'basic_monthly_salary' => '25000.00',
            'effective_date' => $effective,
        ]);
    }

    public function test_past_effective_date_updates_the_live_salary_immediately(): void
    {
        $employee = $this->makeEmployee();

        $this->approveRaise($employee, '25000.00', '2026-05-01');

        $this->assertSame('25000.00', (string) $employee->fresh()->basic_monthly_salary);
        $this->assertNotNull(
            DB::table('salary_adjustments')->where('employee_id', $employee->id)->value('live_applied_at'),
        );
    }

    // ─── Deferred application command ────────────────────────────

    private function seedAppliedAdjustment(Employee $employee, string $toSalary, string $effectiveDate): void
    {
        DB::table('salary_adjustments')->insert([
            'employee_id' => $employee->id,
            'from_basic_monthly_salary' => '20000.00',
            'to_basic_monthly_salary' => $toSalary,
            'effective_date' => $effectiveDate,
            'reason' => 'Merit',
            'status' => 'approved',
            'applied_at' => now()->subDays(2),
            'requested_by' => $this->user('hr_officer')->id,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(2),
        ]);
    }

    public function test_due_command_applies_deferred_adjustments_in_effective_order(): void
    {
        $employee = $this->makeEmployee();
        $this->seedAppliedAdjustment($employee, '25000.00', now()->subDay()->toDateString());
        $this->seedAppliedAdjustment($employee, '22000.00', now()->subDays(2)->toDateString());

        $this->artisan('hr:apply-due-salary-adjustments')->assertExitCode(0);

        // Latest effective change wins on the live row.
        $this->assertSame('25000.00', (string) $employee->fresh()->basic_monthly_salary);
        $this->assertSame(
            2,
            DB::table('salary_adjustments')->where('employee_id', $employee->id)->whereNotNull('live_applied_at')->count(),
        );

        // Idempotent — a second run changes nothing.
        $this->artisan('hr:apply-due-salary-adjustments')->assertExitCode(0);
        $this->assertSame('25000.00', (string) $employee->fresh()->basic_monthly_salary);
    }

    public function test_due_command_skips_adjustments_whose_effective_date_has_not_arrived(): void
    {
        $employee = $this->makeEmployee();
        $this->seedAppliedAdjustment($employee, '25000.00', now()->addDays(5)->toDateString());

        $this->artisan('hr:apply-due-salary-adjustments')->assertExitCode(0);

        $this->assertSame('20000.00', (string) $employee->fresh()->basic_monthly_salary);
        $this->assertNull(
            DB::table('salary_adjustments')->where('employee_id', $employee->id)->value('live_applied_at'),
        );
    }

    // ─── Payroll must pay the rate that was in force ─────────────

    public function test_a_raise_is_not_paid_before_its_effective_date(): void
    {
        $employee = $this->makeEmployee();

        $this->approveRaise($employee, '25000.00', now()->addDays(10)->toDateString());

        // A cutoff computed after approval but ending long before the
        // effective date must pay the OLD rate.
        $payroll = app(PayrollCalculatorService::class)
            ->computeForEmployee($this->makePeriod('2026-05-16', '2026-05-31', false), $employee);

        $this->assertSame('10000.00', $payroll->basic_pay, 'The raise must not leak into a cutoff that predates it.');
        $this->assertSame('20000.00', (string) $employee->fresh()->basic_monthly_salary);
    }

    public function test_first_history_row_inside_a_period_prorates_from_the_pre_adjustment_rate(): void
    {
        $employee = $this->makeEmployee();

        // Effective May 9 (already past): the live row flips immediately, but
        // the May 1–15 cutoff must still pay May 1–8 at the OLD rate.
        $this->approveRaise($employee, '24000.00', '2026-05-09');
        $this->assertSame('24000.00', (string) $employee->fresh()->basic_monthly_salary);

        $payroll = app(PayrollCalculatorService::class)
            ->computeForEmployee($this->makePeriod('2026-05-01', '2026-05-15', true), $employee);

        // May 1–8 = 8 days @ 20000/2; May 9–15 = 7 days @ 24000/2.
        // 10000 × 8/15 + 12000 × 7/15 = 10933.32
        $this->assertSame('10933.32', $payroll->basic_pay);
    }

    public function test_adjustment_with_past_effective_date_pays_the_new_rate_after_it_takes_effect(): void
    {
        $employee = $this->makeEmployee();

        $this->approveRaise($employee, '24000.00', '2026-05-01');

        $payroll = app(PayrollCalculatorService::class)
            ->computeForEmployee($this->makePeriod('2026-05-16', '2026-05-31', false), $employee);

        $this->assertSame('12000.00', $payroll->basic_pay, 'A fully-effective raise pays the new flat half-month.');
    }
}
