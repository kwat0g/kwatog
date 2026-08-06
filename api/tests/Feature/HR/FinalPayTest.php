<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\FinalPayService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2.9 — FinalPayService behaviour lock-down.
 *
 * What is tested:
 *   1. Final-period salary from live payroll/DTR records
 *   2. Unused convertible leave value conversion (days × derived daily rate)
 *   3. Outstanding loan balance deducted from final pay
 *   4. Negative-total clamped to 0.00 via max(0, plus−less)
 *   5. postJournalEntry() produces a balanced, posted JE (total_debit == total_credit)
 *
 * Gaps / stubs noted inline:
 *   - unreturnedPropertyValue() uses each property's persisted replacement cost.
 *   - postJournalEntry() uses account codes 6010/5050, 1020, 2100, 2070 — test seeds
 *     the minimum required accounts.
 */
class FinalPayTest extends TestCase
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
        // Accounts required by FinalPayService::postJournalEntry()
        // code 6010  — primary; 5050 is the fallback (service picks whichever comes first).
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
        // initiated_by requires an existing user
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

    private function service(): FinalPayService
    {
        return app(FinalPayService::class);
    }

    private function seedOpenPayrollPeriod(string $status = 'draft'): int
    {
        return DB::table('payroll_periods')->insertGetId([
            'period_start' => '2026-05-16',
            'period_end' => '2026-05-31',
            'payroll_date' => '2026-06-05',
            'is_first_half' => false,
            'is_thirteenth_month' => false,
            'status' => $status,
            'created_by' => User::query()->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, float> $hoursByDate */
    private function seedAttendanceHours(Employee $employee, array $hoursByDate): void
    {
        foreach ($hoursByDate as $date => $hours) {
            DB::table('attendances')->insert([
                'employee_id' => $employee->id,
                'date' => $date,
                'regular_hours' => $hours,
                'status' => $hours > 0 ? 'present' : 'absent',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Pro-rated salary — monthly employee
    // ──────────────────────────────────────────────────────────────────────

    public function test_final_period_salary_monthly_uses_live_dtr_day_equivalents(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '22000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceHours($employee, [
            '2026-05-16' => 8.0,
            '2026-05-17' => 8.0,
            '2026-05-18' => 4.0,
        ]);

        $result = $this->service()->compute($clearance);

        $breakdown = $result->final_pay_breakdown;
        $this->assertNotNull($breakdown, 'Breakdown must be set after compute()');

        $this->assertSame('2500.00', $breakdown['last_salary_pro_rated'],
            '₱22,000 / 22 × 2.5 persisted DTR day-equivalents must be paid.');
    }

    public function test_final_period_salary_semi_monthly_uses_live_dtr_day_equivalents(): void
    {
        // 7,150 per cutoff → 14,300 monthly → 650.00/day at the 22-day divisor,
        // the same effective rate the old daily-paid fixture used.
        $employee  = $this->makeEmployee([
            'pay_type'             => 'semi_monthly',
            'semi_monthly_rate'    => '7150.00',
            'basic_monthly_salary' => null,
        ]);
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceHours($employee, [
            '2026-05-16' => 8.0,
            '2026-05-17' => 8.0,
            '2026-05-18' => 4.0,
        ]);

        $result = $this->service()->compute($clearance);

        $breakdown = $result->final_pay_breakdown;
        $this->assertSame('1625.00', $breakdown['last_salary_pro_rated'],
            '₱650 × 2.5 persisted DTR day-equivalents must be paid.');
    }

    public function test_final_period_salary_prefers_computed_payroll_result(): void
    {
        $employee = $this->makeEmployee(['basic_monthly_salary' => '22000.00']);
        $clearance = $this->makeClearance($employee);
        $periodId = $this->seedOpenPayrollPeriod('computed');

        DB::table('payrolls')->insert([
            'payroll_period_id' => $periodId,
            'employee_id' => $employee->id,
            'pay_type' => 'monthly',
            'basic_pay' => '6200.00',
            'leave_pay' => '800.00',
            'tardiness_deduction' => '100.00',
            'undertime_deduction' => '50.00',
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $breakdown = $this->service()->compute($clearance)->final_pay_breakdown;

        $this->assertSame('6850.00', $breakdown['last_salary_pro_rated']);
    }

    public function test_disbursed_period_is_not_paid_again_in_final_pay(): void
    {
        $employee = $this->makeEmployee(['basic_monthly_salary' => '22000.00']);
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod('disbursed');
        $this->seedAttendanceHours($employee, ['2026-05-16' => 8.0]);

        $breakdown = $this->service()->compute($clearance)->final_pay_breakdown;

        $this->assertSame('0.00', $breakdown['last_salary_pro_rated']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Unused convertible leave conversion
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Leave value = SUM(remaining × conversion_rate) days × derived daily rate,
     * where the daily rate is monthly-equivalent salary ÷ 22.
     */
    public function test_unused_convertible_leave_converts_at_daily_rate(): void
    {
        // monthly salary: 22000 → daily rate = 22000/22 = 1000.00/day
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '22000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);

        // Seed a convertible leave type + 5 remaining days at conversion_rate=1.00
        $leaveTypeId = DB::table('leave_types')->insertGetId([
            'name'                         => 'Service Incentive Leave',
            'code'                         => 'SIL',
            'default_balance'              => 5.0,
            'is_paid'                      => true,
            'requires_document'            => false,
            'is_convertible_on_separation' => true,
            'is_convertible_year_end'      => false,
            'conversion_rate'              => 1.00,
            'is_active'                    => true,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);
        DB::table('employee_leave_balances')->insert([
            'employee_id'   => $employee->id,
            'leave_type_id' => $leaveTypeId,
            'year'          => (int) now()->format('Y'),
            'total_credits' => 5.0,
            'used'          => 0.0,
            'remaining'     => 5.0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $result    = $this->service()->compute($clearance);
        $breakdown = $result->final_pay_breakdown;

        // 5 days × 1.00 conversion_rate = 5 convertible days × 1000/day = 5000.00
        $this->assertSame('5000.00', $breakdown['unused_convertible_leave_value'],
            'Unused convertible leave: 5 days × (22000/22) = 5000.00');
    }

    /**
     * A non-convertible leave type must NOT contribute to the leave value.
     */
    public function test_non_convertible_leave_does_not_contribute(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '20000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);

        // Non-convertible leave type
        $leaveTypeId = DB::table('leave_types')->insertGetId([
            'name'                         => 'Sick Leave',
            'code'                         => 'SL',
            'default_balance'              => 10.0,
            'is_paid'                      => true,
            'requires_document'            => false,
            'is_convertible_on_separation' => false,   // ← not convertible
            'is_convertible_year_end'      => false,
            'conversion_rate'              => 1.00,
            'is_active'                    => true,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);
        DB::table('employee_leave_balances')->insert([
            'employee_id'   => $employee->id,
            'leave_type_id' => $leaveTypeId,
            'year'          => (int) now()->format('Y'),
            'total_credits' => 10.0,
            'used'          => 0.0,
            'remaining'     => 10.0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $result    = $this->service()->compute($clearance);
        $breakdown = $result->final_pay_breakdown;

        $this->assertSame('0.00', $breakdown['unused_convertible_leave_value'],
            'Non-convertible leave must not be valued in final pay');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Outstanding loan balance deducted
    // ──────────────────────────────────────────────────────────────────────

    /**
     * An active company_loan with a non-zero balance must be deducted.
     */
    public function test_active_loan_balance_deducted_from_final_pay(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '20000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);

        DB::table('employee_loans')->insert([
            'loan_no'               => 'LN-202605-0001',
            'employee_id'           => $employee->id,
            'loan_type'             => 'company_loan',
            'principal'             => '5000.00',
            'interest_rate'         => 0.00,
            'monthly_amortization'  => '500.00',
            'total_paid'            => '0.00',
            'balance'               => '3000.00',
            'pay_periods_total'     => 10,
            'pay_periods_remaining' => 6,
            'status'                => 'active',
            'is_final_pay_deduction'=> false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $result    = $this->service()->compute($clearance);
        $breakdown = $result->final_pay_breakdown;

        $this->assertSame('3000.00', $breakdown['less_loan_balance'],
            'Active company_loan balance must appear in less_loan_balance');

        // Verify the net is reduced by the loan
        $expectedPlus = (float) $breakdown['gross_plus'];
        $expectedNet  = max(0.0, $expectedPlus - 3000.0);
        $this->assertSame(number_format($expectedNet, 2, '.', ''), $breakdown['net'],
            'Net must equal gross_plus minus loan balance (clamped at 0)');
    }

    /**
     * A paid or cancelled loan must NOT be deducted (only active|pending).
     */
    public function test_paid_loan_is_not_deducted(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '20000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);

        DB::table('employee_loans')->insert([
            'loan_no'               => 'LN-202605-0002',
            'employee_id'           => $employee->id,
            'loan_type'             => 'company_loan',
            'principal'             => '5000.00',
            'interest_rate'         => 0.00,
            'monthly_amortization'  => '500.00',
            'total_paid'            => '5000.00',
            'balance'               => '0.00',
            'pay_periods_total'     => 10,
            'pay_periods_remaining' => 0,
            'status'                => 'paid',   // ← paid, must be excluded
            'is_final_pay_deduction'=> false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $result    = $this->service()->compute($clearance);
        $breakdown = $result->final_pay_breakdown;

        $this->assertSame('0.00', $breakdown['less_loan_balance'],
            'Paid loan must not contribute to less_loan_balance');
    }

    /**
     * An open cash advance (loan_type = cash_advance) is deducted as less_advance.
     */
    public function test_open_cash_advance_is_deducted(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '20000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);

        DB::table('employee_loans')->insert([
            'loan_no'               => 'CA-202605-0001',
            'employee_id'           => $employee->id,
            'loan_type'             => 'cash_advance',
            'principal'             => '1500.00',
            'interest_rate'         => 0.00,
            'monthly_amortization'  => '1500.00',
            'total_paid'            => '0.00',
            'balance'               => '1500.00',
            'pay_periods_total'     => 1,
            'pay_periods_remaining' => 1,
            'status'                => 'active',
            'is_final_pay_deduction'=> false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $result    = $this->service()->compute($clearance);
        $breakdown = $result->final_pay_breakdown;

        $this->assertSame('1500.00', $breakdown['less_advance'],
            'Active cash_advance balance must appear in less_advance');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Negative-total clamped to 0
    // ──────────────────────────────────────────────────────────────────────

    /**
     * When deductions exceed earnings, net is clamped to 0.00 (not negative).
     * Pinning the max(0, ...) at FinalPayService.php:50.
     */
    public function test_net_is_clamped_to_zero_when_deductions_exceed_earnings(): void
    {
        // Low salary: monthly 10000 → last_salary = 5000, thirteenth ≈ 833.33
        // No leave balance seeded (= 0.00 leave value)
        // gross_plus ≈ 5833.33
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '10000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);

        // Seed a large loan that exceeds gross_plus
        DB::table('employee_loans')->insert([
            'loan_no'               => 'LN-202605-0099',
            'employee_id'           => $employee->id,
            'loan_type'             => 'company_loan',
            'principal'             => '50000.00',
            'interest_rate'         => 0.00,
            'monthly_amortization'  => '5000.00',
            'total_paid'            => '0.00',
            'balance'               => '50000.00',  // far exceeds earnings
            'pay_periods_total'     => 10,
            'pay_periods_remaining' => 10,
            'status'                => 'active',
            'is_final_pay_deduction'=> false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $result    = $this->service()->compute($clearance);
        $breakdown = $result->final_pay_breakdown;

        // Net must be clamped at 0.00 — never negative
        $this->assertSame('0.00', $breakdown['net'],
            'Net must be clamped at 0.00 when deductions exceed earnings (max(0, plus-less))');

        // The clearance record should also reflect 0
        $this->assertSame('0.00', (string) number_format((float) $result->final_pay_amount, 2, '.', ''),
            'final_pay_amount on clearance must also be 0.00');
    }

    /**
     * When earnings exceed deductions, net must be a positive number (not clamped).
     */
    public function test_net_is_positive_when_earnings_exceed_deductions(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '40000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceHours($employee, ['2026-05-16' => 8.0]);

        $result    = $this->service()->compute($clearance);
        $breakdown = $result->final_pay_breakdown;

        $this->assertGreaterThan(0.0, (float) $breakdown['net'],
            'Net must be positive when there are no deductions');
        $this->assertTrue($result->final_pay_computed,
            'final_pay_computed flag must be set to true');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. postJournalEntry() — balanced JE
    // ──────────────────────────────────────────────────────────────────────

    /**
     * postJournalEntry() must produce a JE where total_debit == total_credit.
     * Also verifies the JE is posted (not draft).
     */
    public function test_post_journal_entry_produces_balanced_posted_entry(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '24000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceHours($employee, [
            '2026-05-16' => 8.0,
            '2026-05-17' => 8.0,
            '2026-05-18' => 8.0,
        ]);

        // Add a loan to exercise the loans_payable credit line
        DB::table('employee_loans')->insert([
            'loan_no'               => 'LN-202605-0010',
            'employee_id'           => $employee->id,
            'loan_type'             => 'company_loan',
            'principal'             => '2000.00',
            'interest_rate'         => 0.00,
            'monthly_amortization'  => '500.00',
            'total_paid'            => '0.00',
            'balance'               => '2000.00',
            'pay_periods_total'     => 4,
            'pay_periods_remaining' => 4,
            'status'                => 'active',
            'is_final_pay_deduction'=> false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $svc = $this->service();
        $clearance = $svc->compute($clearance);

        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $poster = User::factory()->create(['role_id' => $role->id]);

        $je = $svc->postJournalEntry($clearance, $poster);

        // JE must be posted
        $this->assertSame(JournalEntryStatus::Posted->value, $je->status->value,
            'Journal entry must be in Posted status after postJournalEntry()');

        // Reload with lines to check balance
        $je->loadMissing('lines');
        $totalDebit  = $je->lines->sum(fn ($l) => (float) $l->debit);
        $totalCredit = $je->lines->sum(fn ($l) => (float) $l->credit);

        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.005,
            "JE must be balanced: DR {$totalDebit} must equal CR {$totalCredit}");

        // Must have at least 2 lines (DR + CR)
        $this->assertGreaterThanOrEqual(2, $je->lines->count(),
            'JE must have at least 2 lines');

        // Debit must equal the gross_plus from the breakdown
        $breakdown = $clearance->final_pay_breakdown;
        $grossPlus = (float) ($breakdown['gross_plus'] ?? 0);
        $this->assertEqualsWithDelta($grossPlus, $totalDebit, 0.005,
            'DR side of JE must equal gross_plus from the breakdown');
    }

    /**
     * postJournalEntry() must throw when compute() has not been called yet.
     */
    public function test_post_journal_entry_throws_if_not_yet_computed(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);
        // Do NOT call compute() first

        $role   = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $poster = User::factory()->create(['role_id' => $role->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/compute final pay/i');

        $this->service()->postJournalEntry($clearance, $poster);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. Idempotency / state checks
    // ──────────────────────────────────────────────────────────────────────

    /**
     * compute() must persist the breakdown and mark final_pay_computed = true.
     */
    public function test_compute_persists_breakdown_on_clearance(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '18000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);

        $result = $this->service()->compute($clearance);

        $this->assertTrue($result->final_pay_computed, 'final_pay_computed must be true');
        $this->assertNotNull($result->final_pay_breakdown, 'final_pay_breakdown must not be null');
        $this->assertNotNull($result->final_pay_amount, 'final_pay_amount must not be null');

        // All breakdown keys must be present
        $requiredKeys = [
            'last_salary_pro_rated',
            'unused_convertible_leave_value',
            'pro_rated_13th_month',
            'less_loan_balance',
            'less_unreturned_property_value',
            'less_advance',
            'gross_plus',
            'gross_less',
            'net',
        ];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result->final_pay_breakdown,
                "Breakdown must contain key: {$key}");
        }

        // Verify persisted to DB
        $fresh = Clearance::find($result->id);
        $this->assertTrue($fresh->final_pay_computed);
        $this->assertNotNull($fresh->final_pay_breakdown);
    }

    public function test_thirteenth_month_rebuilds_from_computed_ytd_basic_pay(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '24000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);
        $periodId = $this->seedOpenPayrollPeriod('computed');
        DB::table('payrolls')->insert([
            'payroll_period_id' => $periodId,
            'employee_id' => $employee->id,
            'pay_type' => 'monthly',
            'basic_pay' => '120000.00',
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result    = $this->service()->compute($clearance);
        $breakdown = $result->final_pay_breakdown;

        $this->assertSame('10000.00', $breakdown['pro_rated_13th_month'],
            '13th month must equal persisted year-to-date basic pay divided by 12.');
    }

    /**
     * When a thirteenth_month_accruals row exists, it should be used instead
     * of the fallback.
     */
    public function test_thirteenth_month_uses_accrual_row_when_present(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '24000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);

        DB::table('thirteenth_month_accruals')->insert([
            'employee_id'       => $employee->id,
            'year'              => (int) now()->format('Y'),
            'total_basic_earned'=> '120000.00',
            'accrued_amount'    => '10000.00',  // different from salary/12 fallback (2000)
            'is_paid'           => false,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $result    = $this->service()->compute($clearance);
        $breakdown = $result->final_pay_breakdown;

        $this->assertSame('10000.00', $breakdown['pro_rated_13th_month'],
            '13th month must use accrued_amount from accruals table when present');
    }

    /**
     * Unreturned property uses real replacement values stored with each asset.
     */
    public function test_unreturned_property_uses_persisted_replacement_cost(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '40000.00', 'pay_type' => 'monthly']);
        $clearance = $this->makeClearance($employee);

        // Seed 2 lost items
        $now = now();
        DB::table('employee_property')->insert([
            ['employee_id' => $employee->id, 'item_name' => 'Safety Helmet', 'quantity' => 1, 'replacement_unit_cost' => '1750.00',
             'date_issued' => '2025-01-01', 'status' => 'lost', 'created_at' => $now, 'updated_at' => $now],
            ['employee_id' => $employee->id, 'item_name' => 'Work Gloves',   'quantity' => 1, 'replacement_unit_cost' => '250.00',
             'date_issued' => '2025-01-01', 'status' => 'lost', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $result    = $this->service()->compute($clearance);
        $breakdown = $result->final_pay_breakdown;

        $this->assertSame('2000.00', $breakdown['less_unreturned_property_value'],
            'Final pay must sum the persisted replacement value of each lost property item.');
    }
}
