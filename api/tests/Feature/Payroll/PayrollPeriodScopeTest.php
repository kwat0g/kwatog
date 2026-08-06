<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Models\PayrollCycleClaim;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scoped payroll periods + the double-pay guard.
 *
 * A period may be limited to employment types, pay types and/or departments so
 * HR can pay probationary staff, or one department, on their own cutoff. That
 * necessarily means two periods can cover the same dates, which removed the old
 * blanket "no overlapping periods" rule. These tests pin what replaced it:
 *
 *   - scope filters actually narrow the batch (and AND together)
 *   - overlapping periods are allowed ONLY when their scopes are disjoint
 *   - no employee can be paid twice for one cutoff, even across two periods
 *   - voiding a period frees its employees for a replacement run
 */
class PayrollPeriodScopeTest extends TestCase
{
    use RefreshDatabase;

    private PayrollPeriodService $periods;
    private PayrollCalculatorService $calc;
    private Department $production;
    private Department $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);

        $this->periods = app(PayrollPeriodService::class);
        $this->calc    = app(PayrollCalculatorService::class);

        $this->production = Department::create(['name' => 'Production', 'code' => 'PRD']);
        $this->admin      = Department::create(['name' => 'Admin', 'code' => 'ADM']);
    }

    private function hrUser(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'hr_officer')->value('id')]);
    }

    private function employee(Department $dept, string $employmentType, string $payType = 'monthly'): Employee
    {
        $pos = Position::create(['title' => 'Staff '.uniqid(), 'department_id' => $dept->id]);

        return Employee::factory()->create([
            'department_id'         => $dept->id,
            'position_id'           => $pos->id,
            'employment_type'       => $employmentType,
            'pay_type'              => $payType,
            'basic_monthly_salary'  => $payType === 'monthly' ? '20000.00' : null,
            'semi_monthly_rate'     => $payType === 'semi_monthly' ? '10000.00' : null,
            'date_hired'            => '2025-01-01',
            'status'                => 'active',
        ]);
    }

    /** @param array<string, mixed> $scope */
    private function makePeriod(array $scope = [], string $start = '2026-04-01', string $end = '2026-04-15'): PayrollPeriod
    {
        return $this->periods->create(array_merge([
            'period_start'  => $start,
            'period_end'    => $end,
            'payroll_date'  => $end,
            'is_first_half' => true,
        ], $scope), $this->hrUser());
    }

    // ─── Scope resolution ────────────────────────────────────────

    public function test_unscoped_period_pays_every_active_employee(): void
    {
        $this->employee($this->production, 'regular');
        $this->employee($this->admin, 'probationary');

        $period = $this->makePeriod();

        $this->assertTrue($period->isCompanyWide());
        $this->assertNull($period->scopeLabel());
        $this->assertCount(2, $this->periods->availableEmployees($period));
    }

    public function test_employment_type_scope_narrows_the_batch(): void
    {
        $regular      = $this->employee($this->production, 'regular');
        $probationary = $this->employee($this->production, 'probationary');
        $this->employee($this->production, 'contractual');

        $period = $this->makePeriod(['scope_employment_types' => ['regular', 'probationary']]);

        $ids = $this->periods->availableEmployees($period)->pluck('id')->all();

        $this->assertFalse($period->isCompanyWide());
        $this->assertEqualsCanonicalizing([$regular->id, $probationary->id], $ids);
    }

    public function test_department_scope_narrows_the_batch(): void
    {
        $inScope = $this->employee($this->production, 'regular');
        $this->employee($this->admin, 'regular');

        $period = $this->makePeriod(['scope_department_ids' => [$this->production->hash_id]]);

        $this->assertSame([$inScope->id], $this->periods->availableEmployees($period)->pluck('id')->all());
    }

    public function test_pay_type_scope_narrows_the_batch(): void
    {
        $this->employee($this->production, 'regular', 'monthly');
        $semiMonthly = $this->employee($this->production, 'regular', 'semi_monthly');

        $period = $this->makePeriod(['scope_pay_types' => ['semi_monthly']]);

        $this->assertSame([$semiMonthly->id], $this->periods->availableEmployees($period)->pluck('id')->all());
    }

    public function test_scope_filters_and_together_rather_than_or(): void
    {
        // Only this one satisfies BOTH filters.
        $target = $this->employee($this->production, 'probationary');
        // Right type, wrong department.
        $this->employee($this->admin, 'probationary');
        // Right department, wrong type.
        $this->employee($this->production, 'regular');

        $period = $this->makePeriod([
            'scope_employment_types' => ['probationary'],
            'scope_department_ids'   => [$this->production->hash_id],
        ]);

        $this->assertSame([$target->id], $this->periods->availableEmployees($period)->pluck('id')->all());
    }

    public function test_unknown_department_is_rejected(): void
    {
        $this->employee($this->production, 'regular');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('no longer exist');

        $this->makePeriod(['scope_department_ids' => ['zzzzzznotreal']]);
    }

    // ─── Overlap rules ──────────────────────────────────────────

    public function test_two_periods_with_disjoint_scopes_may_share_dates(): void
    {
        $this->employee($this->production, 'probationary');
        $this->employee($this->production, 'contractual');

        $first  = $this->makePeriod(['scope_employment_types' => ['probationary']]);
        $second = $this->makePeriod(['scope_employment_types' => ['contractual']]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertStringContainsString('Probationary', $first->label());
        $this->assertStringContainsString('Contractual', $second->label());
    }

    public function test_overlapping_scopes_over_the_same_dates_are_rejected(): void
    {
        $this->employee($this->production, 'probationary');

        $this->makePeriod(['scope_employment_types' => ['probationary']]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('would be paid twice');

        // 'regular' alone would be disjoint; including 'probationary' collides.
        $this->makePeriod(['scope_employment_types' => ['probationary', 'regular']]);
    }

    public function test_company_wide_period_cannot_be_added_over_a_scoped_one(): void
    {
        $this->employee($this->production, 'probationary');

        $this->makePeriod(['scope_employment_types' => ['probationary']]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Scope this run');

        $this->makePeriod();
    }

    public function test_scoped_period_cannot_be_added_over_a_company_wide_one(): void
    {
        $this->employee($this->production, 'probationary');

        $this->makePeriod();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already covers these dates');

        $this->makePeriod(['scope_employment_types' => ['probationary']]);
    }

    public function test_a_voided_period_does_not_block_a_replacement(): void
    {
        $this->employee($this->production, 'regular');

        $original = $this->makePeriod();
        $original->forceFill(['status' => 'voided'])->save();

        // Same dates, same (company-wide) scope — allowed because the original
        // has been withdrawn.
        $replacement = $this->makePeriod();

        $this->assertNotSame($original->id, $replacement->id);
    }

    // ─── The double-pay guard ───────────────────────────────────

    public function test_an_employee_cannot_be_paid_twice_for_the_same_cutoff(): void
    {
        // One employee who satisfies BOTH scopes — the collision check passes at
        // create time because department and employment-type scopes look
        // disjoint on paper, so only the runtime claim can catch this.
        $employee = $this->employee($this->production, 'probationary');
        $this->employee($this->admin, 'regular'); // makes the second scope non-empty

        $byType = $this->makePeriod(['scope_employment_types' => ['probationary']]);

        // Build the second period directly: create() would (correctly) refuse
        // it. This asserts the guard holds even if a period is introduced by a
        // path that skips that validation — a seeder, a fixture, a future
        // endpoint.
        $byDept = PayrollPeriod::factory()->create([
            'period_start'         => '2026-04-01',
            'period_end'           => '2026-04-15',
            'payroll_date'         => '2026-04-15',
            'is_first_half'        => true,
            'scope_department_ids' => [$this->production->id],
        ]);
        $byDept->forceFill(['status' => 'draft'])->save();

        $this->calc->computeForEmployee($byType, $employee);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already paid for this pay cycle');

        $this->calc->computeForEmployee($byDept, $employee);
    }

    public function test_a_blocked_second_payment_leaves_no_partial_rows(): void
    {
        $employee = $this->employee($this->production, 'regular');

        $first = $this->makePeriod();
        $this->calc->computeForEmployee($first, $employee);

        $second = PayrollPeriod::factory()->create([
            'period_start'  => '2026-04-01',
            'period_end'    => '2026-04-15',
            'payroll_date'  => '2026-04-15',
            'is_first_half' => true,
        ]);
        $second->forceFill(['status' => 'draft'])->save();

        try {
            $this->calc->computeForEmployee($second, $employee);
            $this->fail('Expected the cycle guard to refuse a second payment.');
        } catch (BusinessRuleException) {
            // expected
        }

        // The whole computation must roll back — no orphan payroll row, no
        // deduction details, no loan payments against the rejected period.
        $this->assertSame(0, $second->payrolls()->count());
        $this->assertSame(1, PayrollCycleClaim::where('employee_id', $employee->id)->count());
    }

    public function test_recomputing_the_same_period_is_not_blocked_by_its_own_claim(): void
    {
        $employee = $this->employee($this->production, 'regular');
        $period   = $this->makePeriod();

        $first = $this->calc->computeForEmployee($period, $employee);
        $again = $this->calc->computeForEmployee($period, $employee);

        $this->assertNotSame($first->id, $again->id, 'recompute replaces the payroll row');
        $this->assertSame($again->net_pay, $first->net_pay);
        $this->assertSame(1, PayrollCycleClaim::where('employee_id', $employee->id)->count());
    }

    public function test_the_two_halves_of_a_month_are_separate_cycles(): void
    {
        $employee = $this->employee($this->production, 'regular');

        $firstHalf = $this->makePeriod([], '2026-04-01', '2026-04-15');
        $this->calc->computeForEmployee($firstHalf, $employee);

        $secondHalf = $this->periods->create([
            'period_start'  => '2026-04-16',
            'period_end'    => '2026-04-30',
            'payroll_date'  => '2026-04-30',
            'is_first_half' => false,
        ], $this->hrUser());

        // Must NOT be blocked — a different cutoff is a different cycle.
        $payroll = $this->calc->computeForEmployee($secondHalf, $employee);

        $this->assertNotNull($payroll->id);
        $this->assertSame(2, PayrollCycleClaim::where('employee_id', $employee->id)->count());
    }

    public function test_voiding_a_period_releases_its_cycle_claims(): void
    {
        $employee = $this->employee($this->production, 'regular');
        $period   = $this->makePeriod();
        $this->calc->computeForEmployee($period, $employee);

        $this->assertSame(1, PayrollCycleClaim::where('employee_id', $employee->id)->count());

        // void() only accepts a finalized period.
        $period->forceFill(['status' => 'finalized'])->save();
        $this->periods->void($period->fresh(), $this->hrUser(), 'Wrong scope selected');

        $this->assertSame(0, PayrollCycleClaim::where('employee_id', $employee->id)->count());

        // The employee is payable again by a replacement run.
        $replacement = $this->makePeriod();
        $payroll = $this->calc->computeForEmployee($replacement, $employee);
        $this->assertNotNull($payroll->id);
    }

    // ─── Cycle is derived from dates, never from the label ──────

    /**
     * The exploit this closes: enter second-half dates but tick "1st half", then
     * first-half dates ticked "2nd half". Both periods' cycle keys inverted, so
     * the guard read two different cycles and paid the same employee twice for
     * one month — and government contributions landed on the wrong cutoff.
     */
    public function test_the_half_is_derived_from_the_dates_not_the_submitted_flag(): void
    {
        $secondHalfDates = $this->periods->create([
            'period_start'  => '2026-08-16',
            'period_end'    => '2026-08-31',
            'payroll_date'  => '2026-08-31',
            'is_first_half' => true, // a lie — must be ignored
        ], $this->hrUser());

        $this->assertFalse($secondHalfDates->is_first_half, 'stored flag must be corrected to match the dates');
        $this->assertSame('2026-08-H2', $secondHalfDates->cycleKey());

        $firstHalfDates = $this->periods->create([
            'period_start'  => '2026-08-01',
            'period_end'    => '2026-08-15',
            'payroll_date'  => '2026-08-15',
            'is_first_half' => false, // also a lie
        ], $this->hrUser());

        $this->assertTrue($firstHalfDates->is_first_half);
        $this->assertSame('2026-08-H1', $firstHalfDates->cycleKey());
    }

    public function test_a_mislabelled_period_cannot_double_pay_within_one_month(): void
    {
        $employee = $this->employee($this->production, 'regular');

        $real = $this->makePeriod([], '2026-08-01', '2026-08-15');
        $this->calc->computeForEmployee($real, $employee);

        // Same window, mislabelled the other way. Before the fix this produced
        // cycle key 2026-08-H2 and sailed straight past the guard.
        $impostor = PayrollPeriod::factory()->create([
            'period_start'  => '2026-08-01',
            'period_end'    => '2026-08-15',
            'payroll_date'  => '2026-08-15',
            'is_first_half' => false,
        ]);
        $impostor->forceFill(['status' => 'draft'])->save();

        $this->assertSame('2026-08-H1', $impostor->cycleKey(), 'the key must follow the dates, not the flag');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already paid for this pay cycle');

        $this->calc->computeForEmployee($impostor, $employee);
    }

    public function test_government_deductions_follow_the_real_first_half(): void
    {
        $employee = $this->employee($this->production, 'regular');

        // Second-half window mislabelled as the first half. Gov contributions
        // must NOT be withheld here — they belong to the genuine first half.
        $period = $this->periods->create([
            'period_start'  => '2026-08-16',
            'period_end'    => '2026-08-31',
            'payroll_date'  => '2026-08-31',
            'is_first_half' => true,
        ], $this->hrUser());

        $payroll = $this->calc->computeForEmployee($period, $employee);

        $this->assertSame('0.00', $payroll->sss_ee);
        $this->assertSame('0.00', $payroll->philhealth_ee);
        $this->assertSame('0.00', $payroll->pagibig_ee);
    }

    public function test_a_cutoff_crossing_the_15th_16th_boundary_is_refused(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('one half of the month');

        $this->makePeriod([], '2026-08-10', '2026-08-20');
    }

    public function test_a_cutoff_spanning_two_months_is_refused(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('one month');

        $this->makePeriod([], '2026-08-20', '2026-09-10');
    }

    public function test_both_halves_of_a_month_can_still_be_created(): void
    {
        $this->employee($this->production, 'regular');

        $first  = $this->makePeriod([], '2026-08-01', '2026-08-15');
        $second = $this->makePeriod([], '2026-08-16', '2026-08-31');

        $this->assertSame('2026-08-H1', $first->cycleKey());
        $this->assertSame('2026-08-H2', $second->cycleKey());
    }

    // ─── payroll_date must belong to its cutoff ─────────────────

    /**
     * payroll_date is not cosmetic: it selects the effective-dated government
     * contribution tables, the de minimis month, and the GL posting date. Only
     * `>= period_end` was enforced, so an Aug 2029 cutoff could carry a 2034
     * payroll date and compute against a different year's SSS schedule —
     * roughly ₱100/employee off between the 2024 and 2025 tables, silently.
     */
    public function test_a_payroll_date_far_beyond_the_cutoff_is_refused(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('government contribution tables');

        $this->periods->create([
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-15',
            'payroll_date' => '2031-01-31',
        ], $this->hrUser());
    }

    public function test_a_payroll_date_before_the_cutoff_is_refused(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('cannot fall before the cutoff');

        $this->periods->create([
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-15',
            'payroll_date' => '2026-07-01',
        ], $this->hrUser());
    }

    public function test_a_normally_delayed_payroll_date_is_accepted(): void
    {
        // Paid a fortnight after the cutoff closed — routine, must not be blocked.
        $period = $this->periods->create([
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-15',
            'payroll_date' => '2026-08-31',
        ], $this->hrUser());

        $this->assertSame('2026-08-31', $period->payroll_date->toDateString());
    }

    public function test_the_grace_window_is_configurable(): void
    {
        app(\App\Common\Services\SettingsService::class)->set('payroll.payroll_date.max_days_after_period_end', 5);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('more than 5 days');

        $this->periods->create([
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-15',
            'payroll_date' => '2026-08-25', // 10 days — inside the default 45, outside the configured 5
        ], $this->hrUser());
    }

    // ─── Compute-time scope guard ───────────────────────────────

    public function test_a_scope_matching_nobody_is_refused_at_compute(): void
    {
        // Only regular staff exist; the period asks for project-based.
        $this->employee($this->production, 'regular');
        $period = $this->makePeriod(['scope_employment_types' => ['project_based']]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('matches no active employee');

        $this->periods->claimForCompute($period, $this->hrUser());
    }

    public function test_scope_preview_reports_headcount_and_existing_claims(): void
    {
        $paid = $this->employee($this->production, 'regular');
        $this->employee($this->admin, 'regular');

        $period = $this->makePeriod(['scope_department_ids' => [$this->production->hash_id]]);
        $this->calc->computeForEmployee($period, $paid);

        $preview = $this->periods->scopePreview([
            'period_start'  => '2026-04-01',
            'period_end'    => '2026-04-15',
            'is_first_half' => true,
        ]);

        $this->assertTrue($preview['is_company_wide']);
        $this->assertSame(2, $preview['employee_count']);
        $this->assertSame(2, $preview['total_active']);
        // The already-paid employee is surfaced so the operator sees the clash
        // before creating a colliding period.
        $this->assertSame(1, $preview['already_paid_count']);
        $this->assertSame($paid->employee_no, $preview['already_paid_sample'][0]['employee_no']);
    }
}
