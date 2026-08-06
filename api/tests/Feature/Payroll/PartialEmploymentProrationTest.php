<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollAnomalyService;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Basic pay and the government-contribution basis must describe the SAME span of
 * employment.
 *
 * Basic pay is flat per cutoff since migration 0437, which is only correct for
 * someone employed the whole cutoff. Two ways to be employed for part of one:
 *
 *   hired mid-period       — pro-rated since Sprint 3
 *   separated mid-period   — was NOT, so a leaver banked the full half-month,
 *                            and FinalPayService reads payroll.basic_pay
 *                            verbatim, so it flowed straight into final pay
 *
 * The contribution basis had the mirror-image bug from BOTH directions: it used
 * the nominal monthly salary regardless, so a 3-of-15-day cutoff earning ₱1,892
 * was assessed a full month's ₱1,623 — an 86% deduction ratio that clamped net
 * to near zero and raised high_deduction, which blocks finalize(). That is the
 * same disagreement that made the daily pay type unusable.
 */
class PartialEmploymentProrationTest extends TestCase
{
    use RefreshDatabase;

    private PayrollCalculatorService $calc;
    private Department $dept;

    /** Full-cutoff figures for a ₱9,460/cutoff employee, for comparison. */
    private const FULL_BASIC = '9460.00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);

        $this->calc = app(PayrollCalculatorService::class);
        $this->dept = Department::create(['name' => 'Production', 'code' => 'PRD']);
    }

    private function employee(string $dateHired = '2025-01-01'): Employee
    {
        $pos = Position::create(['title' => 'Operator', 'department_id' => $this->dept->id]);

        return Employee::factory()->create([
            'department_id'         => $this->dept->id,
            'position_id'           => $pos->id,
            'employment_type'       => 'regular',
            'pay_type'              => 'semi_monthly',
            'basic_monthly_salary'  => null,
            'semi_monthly_rate'     => '9460.00',
            'date_hired'            => $dateHired,
            'status'                => 'active',
        ]);
    }

    /** First half → government contributions are withheld, which is the interesting case. */
    private function period(): PayrollPeriod
    {
        $p = PayrollPeriod::factory()->create([
            'period_start'  => '2026-10-01',
            'period_end'    => '2026-10-15',
            'payroll_date'  => '2026-10-15',
            'is_first_half' => true,
        ]);
        $p->forceFill(['status' => 'draft'])->save();

        return $p->fresh();
    }

    private function separate(Employee $employee, string $separationDate): void
    {
        DB::table('clearances')->insert([
            'clearance_no'      => 'CLR-T-'.substr(uniqid(), -5),
            'employee_id'       => $employee->id,
            'separation_date'   => $separationDate,
            'separation_reason' => 'resignation',
            'clearance_items'   => json_encode([]),
            'status'            => 'in_progress',
            'initiated_by'      => User::factory()->create([
                'role_id' => Role::where('slug', 'hr_officer')->value('id'),
            ])->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    // ─── Baseline: a full cutoff must not change ────────────────

    public function test_a_full_cutoff_is_unaffected_by_proration(): void
    {
        $payroll = $this->calc->computeForEmployee($this->period(), $this->employee());

        $this->assertSame(self::FULL_BASIC, $payroll->basic_pay);
        // Contributions are assessed on the full nominal salary. Exact pesos are
        // whatever the seeded bracket says, so assert the property that matters:
        // a full cutoff is assessed something, and its net is the full amount.
        $this->assertGreaterThan(0.0, (float) $payroll->sss_ee);
        $this->assertGreaterThan(0.0, (float) $payroll->philhealth_ee);
        $this->assertGreaterThan(0.0, (float) $payroll->pagibig_ee);

        // And it stays proportionate — the regression that partial cutoffs broke.
        $ratio = (float) $payroll->total_deductions / max(0.01, (float) $payroll->gross_pay);
        $this->assertLessThan(0.5, $ratio);
    }

    /**
     * The contribution basis for a full cutoff, so partial-cutoff tests can
     * assert "less than a full month" without hardcoding bracket amounts that
     * differ between the seeded test tables and production.
     *
     * @return array{sss: float, philhealth: float, pagibig: float}
     */
    private function fullCutoffContributions(): array
    {
        $payroll = $this->calc->computeForEmployee($this->period(), $this->employee());

        return [
            'sss'        => (float) $payroll->sss_ee,
            'philhealth' => (float) $payroll->philhealth_ee,
            'pagibig'    => (float) $payroll->pagibig_ee,
        ];
    }

    // ─── Separation proration ──────────────────────────────────

    public function test_separating_mid_cutoff_pro_rates_basic_pay(): void
    {
        $employee = $this->employee();
        $this->separate($employee, '2026-10-03'); // 3 of 15 calendar days

        $payroll = $this->calc->computeForEmployee($this->period(), $employee);

        // 9460 × 3/15 = 1892.00
        $this->assertSame('1892.00', $payroll->basic_pay);
        $this->assertLessThan((float) self::FULL_BASIC, (float) $payroll->basic_pay);
    }

    public function test_separating_after_the_cutoff_ends_pays_in_full(): void
    {
        $employee = $this->employee();
        $this->separate($employee, '2026-11-20'); // well after this cutoff

        $payroll = $this->calc->computeForEmployee($this->period(), $employee);

        $this->assertSame(self::FULL_BASIC, $payroll->basic_pay);
    }

    public function test_separating_on_the_last_day_pays_in_full(): void
    {
        $employee = $this->employee();
        $this->separate($employee, '2026-10-15');

        $payroll = $this->calc->computeForEmployee($this->period(), $employee);

        $this->assertSame(self::FULL_BASIC, $payroll->basic_pay);
    }

    public function test_separating_before_the_cutoff_begins_pays_nothing(): void
    {
        $employee = $this->employee();
        $this->separate($employee, '2026-09-20');

        $payroll = $this->calc->computeForEmployee($this->period(), $employee);

        $this->assertSame('0.00', $payroll->basic_pay);
    }

    public function test_the_earliest_separation_date_on_record_wins(): void
    {
        $employee = $this->employee();
        // A re-initiated separation must not be able to extend paid days.
        $this->separate($employee, '2026-10-03');
        $this->separate($employee, '2026-10-14');

        $payroll = $this->calc->computeForEmployee($this->period(), $employee);

        $this->assertSame('1892.00', $payroll->basic_pay, 'the earliest date must bound the pay');
    }

    // ─── Contribution basis follows actual compensation ────────

    /**
     * The defect this closes, reachable from BOTH ends of the employment window.
     */
    public function test_a_partial_cutoff_is_not_assessed_a_full_months_contributions(): void
    {
        $employee = $this->employee();
        $this->separate($employee, '2026-10-03');

        $full = $this->fullCutoffContributions();

        $payroll = $this->calc->computeForEmployee($this->period(), $employee);

        // Assessed on ₱1,892 of actual compensation, not the nominal ₱18,920.
        $this->assertLessThan($full['sss'], (float) $payroll->sss_ee);
        $this->assertLessThan($full['philhealth'], (float) $payroll->philhealth_ee);
        $this->assertLessThan($full['pagibig'], (float) $payroll->pagibig_ee);

        // The whole point: deductions must stay proportionate to the pay.
        $ratio = (float) $payroll->total_deductions / max(0.01, (float) $payroll->gross_pay);
        $this->assertLessThan(0.5, $ratio, 'a partial cutoff must not be swamped by a full month of deductions');
        $this->assertGreaterThan(0.0, (float) $payroll->net_pay, 'net must not clamp to zero');
    }

    /**
     * Mid-period hires have ALWAYS been assessed this way — the bug predates the
     * semi-monthly conversion. Pinned so it cannot regress from that direction.
     */
    public function test_a_mid_period_hire_is_not_assessed_a_full_months_contributions(): void
    {
        $full = $this->fullCutoffContributions();

        $employee = $this->employee('2026-10-13'); // 3 of 15 days

        $payroll = $this->calc->computeForEmployee($this->period(), $employee);

        $this->assertSame('1892.00', $payroll->basic_pay);
        $this->assertLessThan($full['sss'], (float) $payroll->sss_ee);

        $ratio = (float) $payroll->total_deductions / max(0.01, (float) $payroll->gross_pay);
        $this->assertLessThan(0.5, $ratio);
    }

    public function test_a_partial_cutoff_no_longer_raises_a_high_deduction_flag(): void
    {
        $employee = $this->employee();
        $period   = $this->period();
        $this->separate($employee, '2026-10-03');

        $this->calc->computeForEmployee($period, $employee);
        app(PayrollAnomalyService::class)->detect($period);

        $this->assertDatabaseMissing('payroll_anomaly_flags', [
            'payroll_period_id' => $period->id,
            'employee_id'       => $employee->id,
            'flag_type'         => 'high_deduction',
        ]);
        $this->assertDatabaseMissing('payroll_anomaly_flags', [
            'payroll_period_id' => $period->id,
            'employee_id'       => $employee->id,
            'flag_type'         => 'zero_pay',
        ]);
    }

    /**
     * Second-half cutoffs withhold no government contributions at all, so
     * proration must touch basic pay only.
     */
    public function test_second_half_separation_pro_rates_basic_without_contributions(): void
    {
        $employee = $this->employee();
        $period   = PayrollPeriod::factory()->create([
            'period_start'  => '2026-10-16',
            'period_end'    => '2026-10-31',
            'payroll_date'  => '2026-10-31',
            'is_first_half' => false,
        ]);
        $period->forceFill(['status' => 'draft'])->save();

        $this->separate($employee, '2026-10-20'); // 5 of 16 days

        $payroll = $this->calc->computeForEmployee($period->fresh(), $employee);

        $this->assertSame('0.00', $payroll->sss_ee);
        $this->assertSame('0.00', $payroll->philhealth_ee);
        $this->assertLessThan((float) self::FULL_BASIC, (float) $payroll->basic_pay);
        $this->assertGreaterThan(0.0, (float) $payroll->basic_pay);
    }
}
