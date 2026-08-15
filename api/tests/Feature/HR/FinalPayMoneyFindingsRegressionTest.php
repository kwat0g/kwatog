<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Exceptions\BusinessRuleException;
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
use App\Modules\Loans\Enums\LoanPaymentType;
use App\Modules\Loans\Models\EmployeeLoan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase-2 regression tests for the process-hardening audit's final-pay
 * findings (docs/PROCESS-HARDENING-AUDIT-2026-08-11.md §3):
 *
 *   P05-01  final-pay JE cannot balance when deductions exceed earnings;
 *           the separation dead-ends on an HTTP 500 (silent failure)
 *   P05-02  the breakdown is a snapshot; finalize re-checks the loan but
 *           spends the stale figure, double-charging a settled loan
 */
class FinalPayMoneyFindingsRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seedMinimumAccounts();
    }

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
            'status'           => ClearanceStatus::Completed->value,
            'initiated_by'     => $user->id,
        ], $overrides));
    }

    /** An open (non-disbursed) payroll period covering the separation date. */
    private function seedOpenPayrollPeriod(): void
    {
        DB::table('payroll_periods')->insert([
            'period_start'       => '2026-05-16',
            'period_end'         => '2026-05-31',
            'payroll_date'       => '2026-06-05',
            'is_first_half'      => false,
            'is_thirteenth_month'=> false,
            'status'             => 'draft',
            'created_by'         => User::query()->firstOrFail()->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /** One 8-hour worked day inside the final period → 20000/22 ≈ 909.09. */
    private function seedAttendanceDay(Employee $employee): void
    {
        DB::table('attendances')->insert([
            'employee_id'  => $employee->id,
            'date'         => '2026-05-16',
            'regular_hours'=> 8.0,
            'status'       => 'present',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * An open company loan. status is deliberately left at the DB default
     * (pending) so it is picked up by the final-pay deduction read but must be
     * force-filled to paid by the settle step before finalize.
     */
    private function seedLoan(Employee $employee, string $balance): EmployeeLoan
    {
        return EmployeeLoan::create([
            'loan_no'               => 'LN-'.uniqid(),
            'employee_id'           => $employee->id,
            'loan_type'             => 'company_loan',
            'principal'             => $balance,
            'interest_rate'         => 0.00,
            'monthly_amortization'  => '500.00',
            'total_paid'            => '0.00',
            'balance'               => $balance,
            'pay_periods_total'     => 10,
            'pay_periods_remaining' => 6,
            'is_final_pay_deduction'=> false,
        ]);
    }

    private function makePoster(): User
    {
        return User::factory()->create(['role_id' => Role::query()->orderBy('id')->value('id')]);
    }

    // ─── P05-01 ────────────────────────────────────────────────────────────

    /**
     * P05-01 PROVEN — when deductions (lost property) exceed the final-pay
     * earnings, the JE must still balance: only what this payout can absorb is
     * recovered and the remainder stays on the books — never an unbalanced
     * entry that 500s the separation. The separation must complete (status
     * Finalized) and the JE must be balanced.
     */
    public function test_p05_01_separation_completes_when_deductions_exceed_earnings(): void
    {
        // A leaver whose final cutoff is tiny (1 day = ₱909.09) but who owes a
        // large lost-property charge (₱5,000). Net is clamped at 0.00 and the
        // JE must still balance. Property is not gated by finalize's loan
        // check, so this is the reachable path where deductions exceed earnings.
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '20000.00']);
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceDay($employee);

        DB::table('employee_property')->insert([
            'employee_id'           => $employee->id,
            'item_name'             => 'Laptop',
            'quantity'              => 1,
            'replacement_unit_cost' => '5000.00',
            'date_issued'           => '2025-01-01',
            'status'                => 'lost',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $finalPay = app(FinalPayService::class);
        $computed = $finalPay->compute($clearance);
        $this->assertSame('0.00', $computed->final_pay_breakdown['net']);

        $poster = $this->makePoster();
        $finalized = app(SeparationService::class)->finalize($computed->fresh(), $poster, $finalPay);

        $this->assertSame(ClearanceStatus::Finalized->value, $finalized->status->value);

        $je = DB::table('journal_entries')->where('id', $finalized->journal_entry_id)->firstOrFail();
        $this->assertEquals(
            (string) $je->total_debit,
            (string) $je->total_credit,
            'Final-pay JE must balance even when deductions exceed earnings (P05-01).',
        );
    }

    // ─── P05-02 ────────────────────────────────────────────────────────────

    /**
     * P05-02 PROVEN — when a loan is settled between compute and finalize, the
     * finalize must re-read the live loan balance instead of spending the
     * frozen breakdown figure. The employee must not be charged twice.
     */
    public function test_p05_02_finalize_spends_live_loan_balance_not_stale_breakdown(): void
    {
        $employee  = $this->makeEmployee(['basic_monthly_salary' => '20000.00']);
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceDay($employee);

        $loan = $this->seedLoan($employee, '5000.00');

        // Stage A — compute while the loan is still outstanding (snapshot holds
        // less_loan_balance = 5000).
        $finalPay = app(FinalPayService::class);
        $computed = $finalPay->compute($clearance);
        $this->assertSame('5000.00', $computed->final_pay_breakdown['less_loan_balance']);

        // Stage B — the loan is settled (payroll withheld and credited it)
        // BEFORE finalize is clicked.
        $loan->forceFill(['balance' => '0.00', 'status' => 'paid', 'total_paid' => '5000.00'])->save();
        DB::table('loan_payments')->insert([
            'loan_id'      => $loan->id,
            'amount'       => '5000.00',
            'payment_date' => '2026-05-20',
            'remarks'      => 'Settled via payroll',
            'payment_type' => LoanPaymentType::PayrollDeduction->value,
            'created_at'   => now(),
        ]);

        $poster = $this->makePoster();
        $finalized = app(SeparationService::class)->finalize($computed->fresh(), $poster, $finalPay);

        $je = DB::table('journal_entries')->where('id', $finalized->journal_entry_id)->firstOrFail();
        $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $je->id)->get();

        // The loan is settled — final pay must NOT deduct it again.
        $loansPayableAccount = DB::table('accounts')->where('code', '2100')->value('id');
        $loansPayableCredits = $lines->where('account_id', $loansPayableAccount)->sum(fn ($l) => (float) $l->credit);

        // Re-read from the DB: finalize returns the in-memory clearance whose
        // breakdown predates the refresh postJournalEntry persisted.
        $breakdown = $finalized->fresh()->final_pay_breakdown;
        $this->assertSame('0.00', $breakdown['less_loan_balance'], 'Finalize must re-read the live loan balance.');

        $this->assertEqualsWithDelta(0.0, $loansPayableCredits, 0.01, 'A settled loan must not be deducted from final pay again (P05-02).');
    }
}
