<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\FinalPayService;
use App\Modules\HR\Services\SeparationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HR-01 — the last period can be paid twice, order-dependent.
 *
 * Final pay books the employee's salary for the payroll period covering the
 * separation date, and that same period later pays those days again through
 * the normal payroll run (basic pay is prorated to the separation date). The
 * guard: final pay refuses to compute — and re-checks at the money moment —
 * while the covering period has not been disbursed. Only a disbursed period
 * (already paid) resolves the last-salary component, to 0.00.
 */
class FinalPayDoublePayGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seedMinimumAccounts();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function seedMinimumAccounts(): void
    {
        $accounts = [
            ['code' => '6010', 'name' => 'Salaries & Wages Expense', 'type' => 'expense',   'normal_balance' => 'debit'],
            ['code' => '1020', 'name' => 'Cash in Bank',             'type' => 'asset',     'normal_balance' => 'debit'],
            ['code' => '2100', 'name' => 'Loans Payable',            'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2070', 'name' => 'Accrued Expenses',         'type' => 'liability', 'normal_balance' => 'credit'],
        ];
        foreach ($accounts as $a) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $a['code']],
                array_merge($a, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $pos  = Position::firstOrCreate(['title' => 'Operator', 'department_id' => $dept->id]);

        return Employee::create(array_merge([
            'employee_no'          => 'OGM-'.str_pad((string) random_int(1, 99999), 4, '0', STR_PAD_LEFT),
            'first_name'           => 'Juan',
            'last_name'            => 'Cruz',
            'birth_date'           => '1990-01-01',
            'gender'               => 'male',
            'civil_status'         => 'single',
            'nationality'          => 'Filipino',
            'department_id'        => $dept->id,
            'position_id'          => $pos->id,
            'employment_type'      => 'regular',
            'pay_type'             => 'monthly',
            'basic_monthly_salary' => '20000.00',
            'date_hired'           => '2024-01-01',
            'status'               => 'active',
        ], $overrides));
    }

    private function makeClearance(Employee $employee, array $overrides = []): Clearance
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user = User::factory()->create(['role_id' => $role->id]);

        return Clearance::create(array_merge([
            'clearance_no'     => 'CLR-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'employee_id'      => $employee->id,
            'separation_date'  => '2026-05-31',
            'separation_reason'=> SeparationReason::Resigned->value,
            'clearance_items'  => [],
            'status'           => ClearanceStatus::InProgress->value,
            'initiated_by'     => $user->id,
        ], $overrides));
    }

    /** The payroll period covering the 2026-05-31 separation date. */
    private function seedCoveringPeriod(string $status): int
    {
        return DB::table('payroll_periods')->insertGetId([
            'period_start'        => '2026-05-16',
            'period_end'          => '2026-05-31',
            'payroll_date'        => '2026-06-05',
            'is_first_half'       => false,
            'is_thirteenth_month' => false,
            'status'              => $status,
            'created_by'          => User::query()->firstOrFail()->id,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function poster(): User
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function service(): FinalPayService
    {
        return app(FinalPayService::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // (a) compute refuses while the covering period is undisbursed
    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function undisbursedStatuses(): array
    {
        return [
            'draft'      => ['draft'],
            'processing' => ['processing'],
            'computed'   => ['computed'],
            'approved'   => ['approved'],
            'finalized'  => ['finalized'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('undisbursedStatuses')]
    public function test_compute_is_refused_while_the_covering_period_is_undisbursed(string $status): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);
        $this->seedCoveringPeriod($status);

        try {
            $this->service()->compute($clearance);
            $this->fail("compute() must refuse while the covering period is {$status}.");
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('has not been disbursed', $e->getMessage());
            $this->assertStringContainsString('disburse or void', $e->getMessage());
            $this->assertStringContainsString('May 16–May 31, 2026', $e->getMessage(),
                'The refusal must name the covering period.');
        }

        $this->assertFalse($clearance->fresh()->final_pay_computed,
            'A refused computation must not persist a breakdown.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // (b) a disbursed covering period pays 0.00 — existing behaviour
    // ──────────────────────────────────────────────────────────────────────

    public function test_compute_succeeds_with_zero_last_salary_when_the_covering_period_is_disbursed(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);
        $this->seedCoveringPeriod('disbursed');

        $result = $this->service()->compute($clearance);

        $this->assertTrue($result->final_pay_computed);
        $this->assertSame('0.00', $result->final_pay_breakdown['last_salary_pro_rated'],
            'Payroll already paid the covering period; final pay must not pay it again.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // (c) the guard re-runs at the money moment
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The state machine allows no Disbursed → undisbursed transition, so the
     * reachable race is a covering period APPEARING after a compute that saw
     * none (or a voided one that was recomputed). Posting must re-run the
     * guard under lock and refuse.
     */
    public function test_posting_refuses_when_an_undisbursed_covering_period_appears_after_compute(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);

        $computed = $this->service()->compute($clearance);
        $this->assertSame('0.00', $computed->final_pay_breakdown['last_salary_pro_rated']);

        $this->seedCoveringPeriod('finalized');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('has not been disbursed');

        $this->service()->postJournalEntry($computed->fresh(), $this->poster());
    }

    public function test_finalize_refuses_when_an_undisbursed_covering_period_appears_after_compute(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee, ['status' => ClearanceStatus::Completed->value]);

        $computed = $this->service()->compute($clearance);

        $this->seedCoveringPeriod('computed');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('has not been disbursed');

        app(SeparationService::class)->finalize($computed->fresh(), $this->poster(), $this->service());
    }

    /**
     * Legacy data computed before the guard: the breakdown booked a last
     * salary while the period was still open, and the period has since been
     * disbursed — payroll already paid those days. Posting the JE would pay
     * them a second time.
     */
    public function test_posting_refuses_a_breakdown_that_would_double_pay_a_disbursed_period(): void
    {
        $employee = $this->makeEmployee();
        $clearance = $this->makeClearance($employee, [
            'status' => ClearanceStatus::Completed->value,
            'final_pay_computed' => true,
            'final_pay_amount' => '5000.00',
            'final_pay_breakdown' => [
                'last_salary_pro_rated' => '5000.00',
                'unused_convertible_leave_value' => '0.00',
                'pro_rated_13th_month' => '0.00',
                'less_loan_balance' => '0.00',
                'less_advance' => '0.00',
                'less_unreturned_property_value' => '0.00',
                'gross_plus' => '5000.00',
                'gross_less' => '0.00',
                'net' => '5000.00',
            ],
        ]);
        $this->seedCoveringPeriod('disbursed');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('twice');

        $this->service()->postJournalEntry($clearance, $this->poster());
    }

    /**
     * Positive control for the same legacy shape: with NO covering period the
     * booked last salary is the only payment for those days, so the JE posts.
     */
    public function test_posting_allows_a_last_salary_component_when_no_period_covers_the_separation_date(): void
    {
        $employee = $this->makeEmployee();
        $clearance = $this->makeClearance($employee, [
            'status' => ClearanceStatus::Completed->value,
            'final_pay_computed' => true,
            'final_pay_amount' => '5000.00',
            'final_pay_breakdown' => [
                'last_salary_pro_rated' => '5000.00',
                'unused_convertible_leave_value' => '0.00',
                'pro_rated_13th_month' => '0.00',
                'less_loan_balance' => '0.00',
                'less_advance' => '0.00',
                'less_unreturned_property_value' => '0.00',
                'gross_plus' => '5000.00',
                'gross_less' => '0.00',
                'net' => '5000.00',
            ],
        ]);

        $je = $this->service()->postJournalEntry($clearance, $this->poster());

        $this->assertSame(JournalEntryStatus::Posted, $je->status);
    }
}
