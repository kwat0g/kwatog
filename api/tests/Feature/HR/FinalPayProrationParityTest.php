<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\FinalPayService;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HR-02 — the no-payroll-row fallback of FinalPayService must agree with what
 * payroll WOULD have computed for the same cutoff.
 *
 * The fallback engages exactly when the separation outruns payroll compute.
 * It used to re-derive an attendance-day formula (day-equivalents × monthly ÷
 * 22) that disagreed with payroll's calendar-day flat-basis proration on the
 * same facts. Both sides now delegate to EmployedDayFraction, so the audit's
 * repro scenario — ₱22,000 monthly, Mar 1–15 cutoff, separation Mar 10, 8
 * attended days — must yield the payroll number, not the old ₱8,000.
 */
class FinalPayProrationParityTest extends TestCase
{
    use RefreshDatabase;

    private PayrollCalculatorService $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->calc = app(PayrollCalculatorService::class);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $pos  = Position::firstOrCreate(['title' => 'Operator', 'department_id' => $dept->id]);

        return Employee::factory()->create(array_merge([
            'department_id'        => $dept->id,
            'position_id'          => $pos->id,
            'employment_type'      => 'regular',
            'pay_type'             => 'monthly',
            'basic_monthly_salary' => '22000.00',
            'date_hired'           => '2025-01-01',
            'status'               => 'active',
        ], $overrides));
    }

    /** Mar 1–15 first-half period; status stays draft (open, not disbursed). */
    private function seedOpenPeriod(): int
    {
        return DB::table('payroll_periods')->insertGetId([
            'period_start'        => '2026-03-01',
            'period_end'          => '2026-03-15',
            'payroll_date'        => '2026-03-20',
            'is_first_half'       => true,
            'is_thirteenth_month' => false,
            'status'              => 'draft',
            'created_by'          => User::factory()->create([
                'role_id' => Role::query()->orderBy('id')->value('id'),
            ])->id,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function makeClearance(Employee $employee, string $separationDate): Clearance
    {
        return Clearance::create([
            'clearance_no'     => 'CLR-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'employee_id'      => $employee->id,
            'separation_date'  => $separationDate,
            'separation_reason' => SeparationReason::Resigned->value,
            'clearance_items'  => [],
            'status'           => 'in_progress',
            'initiated_by'     => User::factory()->create([
                'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
            ])->id,
        ]);
    }

    /**
     * The audit's repro: ₱22,000 monthly, Mar 1–15 cutoff, separation Mar 10,
     * 8 attended days. Payroll's own engine computes the flat half-month basic
     * scaled by 10/15 — assert the exact figure the formula yields so the
     * parity assertion below cannot drift.
     */
    public function test_the_audit_repro_scenario_has_one_answer(): void
    {
        $employee = $this->makeEmployee();
        $periodId = $this->seedOpenPeriod();
        $period   = PayrollPeriod::query()->findOrFail($periodId);

        DB::table('clearances')->insert([
            'clearance_no'      => 'CLR-T-'.substr(uniqid(), -5),
            'employee_id'       => $employee->id,
            'separation_date'   => '2026-03-10',
            'separation_reason' => 'resignation',
            'clearance_items'   => json_encode([]),
            'status'            => 'in_progress',
            'initiated_by'      => User::factory()->create([
                'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
            ])->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $payroll = $this->calc->computeForEmployee($period, $employee);

        // halfBasic 11000 × 10/15 (truncated at scale 4 → 0.6666) = 7332.60.
        $this->assertSame('7332.60', $payroll->basic_pay);
    }

    /**
     * No computed payroll row (separation outran the compute) — the fallback
     * must land on the SAME number the calculator produced above.
     */
    public function test_fallback_matches_what_payroll_would_have_computed(): void
    {
        $employee = $this->makeEmployee();
        $this->seedOpenPeriod();

        // 8 attended days would have earned ₱8,000 under the retired
        // attendance-day fallback; they must not move the number now.
        foreach (['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04',
                  '2026-03-05', '2026-03-06', '2026-03-09', '2026-03-10'] as $date) {
            DB::table('attendances')->insert([
                'employee_id'   => $employee->id,
                'date'          => $date,
                'regular_hours' => 8.0,
                'status'        => 'present',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        $clearance = $this->makeClearance($employee, '2026-03-10');
        $breakdown = app(FinalPayService::class)->compute($clearance)->final_pay_breakdown;

        $this->assertSame('7332.60', $breakdown['last_salary_pro_rated']);
    }

    /**
     * Direct engine-vs-fallback equality on the same cutoff, so any future
     * divergence between the two halves of the seam fails here first.
     */
    public function test_engine_and_fallback_agree_for_a_partial_cutoff(): void
    {
        $engineEmployee = $this->makeEmployee();
        $fallbackEmployee = $this->makeEmployee();
        $periodId = $this->seedOpenPeriod();
        $period   = PayrollPeriod::query()->findOrFail($periodId);

        DB::table('clearances')->insert([
            'clearance_no'      => 'CLR-T-'.substr(uniqid(), -5),
            'employee_id'       => $engineEmployee->id,
            'separation_date'   => '2026-03-10',
            'separation_reason' => 'resignation',
            'clearance_items'   => json_encode([]),
            'status'            => 'in_progress',
            'initiated_by'      => User::factory()->create([
                'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
            ])->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $engineBasic = $this->calc->computeForEmployee($period, $engineEmployee)->basic_pay;

        $clearance = $this->makeClearance($fallbackEmployee, '2026-03-10');
        $breakdown = app(FinalPayService::class)->compute($clearance)->final_pay_breakdown;

        $this->assertSame($engineBasic, $breakdown['last_salary_pro_rated']);
    }

    /** A semi-monthly leaver prorates on the same calendar-day fraction. */
    public function test_fallback_prorates_semi_monthly_on_the_same_basis(): void
    {
        $employee = $this->makeEmployee([
            'pay_type'             => 'semi_monthly',
            'semi_monthly_rate'    => '9460.00',
            'basic_monthly_salary' => null,
        ]);
        $this->seedOpenPeriod();

        $clearance = $this->makeClearance($employee, '2026-03-03');
        $breakdown = app(FinalPayService::class)->compute($clearance)->final_pay_breakdown;

        // Flat cutoff 9460 × 3/15 = 1892.00.
        $this->assertSame('1892.00', $breakdown['last_salary_pro_rated']);
    }
}
