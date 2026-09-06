<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\FinalPayService;
use App\Modules\HR\Services\SeparationService;
use App\Modules\Loans\Enums\LoanPaymentType;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Loans\Services\LoanService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LN-02/LN-03 — final pay settles the loan ledger.
 *
 * FinalPayService::compute() now records LoanPayment rows of type final_pay
 * (linked to the clearance) through LoanService::recordPayment, so the
 * separation finalize gate observes settled balances and the JE's
 * "Settle outstanding loan from final pay" arm is reachable. Ordering:
 * compute settles → finalize gate re-reads (balance 0 passes) → JE credits
 * the absorbed amount. Residue the payout cannot absorb keeps the gate
 * blocking until settled manually (POST /loans/{loan}/payments).
 */
class FinalPayLoanSettlementTest extends TestCase
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
        $user = $this->makePoster();

        return Clearance::create(array_merge([
            'clearance_no'      => 'CLR-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'employee_id'       => $employee->id,
            'separation_date'   => '2026-05-31',
            'separation_reason' => SeparationReason::Resigned->value,
            'clearance_items'   => [],
            'status'            => ClearanceStatus::Completed->value,
            'initiated_by'      => $user->id,
        ], $overrides));
    }

    private function makePoster(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    /** Open period covering the separation date; 1 DTR day ≈ 20000/22 = 909.09. */
    private function seedOpenPayrollPeriod(): void
    {
        DB::table('payroll_periods')->insert([
            'period_start'        => '2026-05-16',
            'period_end'          => '2026-05-31',
            'payroll_date'        => '2026-06-05',
            'is_first_half'       => false,
            'is_thirteenth_month' => false,
            'status'              => 'draft',
            'created_by'          => User::query()->firstOrFail()->id,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function seedAttendanceDay(Employee $employee): void
    {
        DB::table('attendances')->insert([
            'employee_id'   => $employee->id,
            'date'          => '2026-05-16',
            'regular_hours' => 8.0,
            'status'        => 'present',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function makeActiveLoan(Employee $employee, string $balance): EmployeeLoan
    {
        // principal = balance keeps the fixture ledger-consistent: reconcile
        // rebuilds balance as schedule total due minus the payment ledger.
        return EmployeeLoan::factory()->create([
            'employee_id'           => $employee->id,
            'loan_type'             => 'company_loan',
            'principal'             => $balance,
            'interest_rate'         => '0.00',
            'monthly_amortization'  => $balance,
            'total_paid'            => '0.00',
            'balance'               => $balance,
            'pay_periods_total'     => 1,
            'pay_periods_remaining' => 1,
        ]);
    }

    private function loansPayableCredits(int $journalEntryId): string
    {
        $account = DB::table('accounts')->where('code', '2100')->value('id');
        $sum = (string) DB::table('journal_entry_lines')
            ->where('journal_entry_id', $journalEntryId)
            ->where('account_id', $account)
            ->sum('credit');

        return number_format((float) $sum, 2, '.', '');
    }

    // ─────────────────────────────────────────────────────────────
    // 1. Compute settles the deducted loan through the ledger
    // ─────────────────────────────────────────────────────────────

    public function test_compute_settles_active_loan_and_records_final_pay_payment(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceDay($employee);
        $loan = $this->makeActiveLoan($employee, '500.00');

        $computed = app(FinalPayService::class)->compute($clearance);

        $breakdown = $computed->final_pay_breakdown;
        $this->assertSame('500.00', $breakdown['less_loan_balance']);
        $this->assertSame('409.09', $breakdown['net'], 'Net must be earnings minus the settled loan.');

        $payment = LoanPayment::query()->where('loan_id', $loan->id)->sole();
        $this->assertSame('500.00', (string) $payment->amount);
        $this->assertSame(LoanPaymentType::FinalPay->value, $payment->payment_type);
        $this->assertSame($clearance->id, $payment->clearance_id);
        $this->assertSame('2026-05-31', $payment->payment_date->toDateString(), 'Settlement is dated the separation date, matching the JE.');

        $loan->refresh();
        $this->assertSame('paid', $loan->status->value);
        $this->assertSame('0.00', (string) $loan->balance);
        $this->assertSame('500.00', (string) $loan->total_paid);
    }

    public function test_recompute_after_settlement_is_idempotent(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceDay($employee);
        $loan = $this->makeActiveLoan($employee, '500.00');

        $service = app(FinalPayService::class);
        $service->compute($clearance);
        $recomputed = $service->compute($clearance->fresh());

        $this->assertSame(1, LoanPayment::query()->where('loan_id', $loan->id)->count(), 'A recompute must not settle the same loan twice.');
        $this->assertSame('500.00', $recomputed->final_pay_breakdown['less_loan_balance'], 'The settlement stays deducted on recompute.');
        $this->assertSame('409.09', $recomputed->final_pay_breakdown['net']);
    }

    public function test_cash_advance_is_also_settled_from_final_pay(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceDay($employee);

        $advance = EmployeeLoan::factory()->create([
            'employee_id'           => $employee->id,
            'loan_type'             => 'cash_advance',
            'principal'             => '300.00',
            'interest_rate'         => '0.00',
            'monthly_amortization'  => '300.00',
            'total_paid'            => '0.00',
            'balance'               => '300.00',
            'pay_periods_total'     => 1,
            'pay_periods_remaining' => 1,
        ]);

        $computed = app(FinalPayService::class)->compute($clearance);

        $this->assertSame('300.00', $computed->final_pay_breakdown['less_advance']);
        $this->assertSame('paid', $advance->fresh()->status->value);
        $this->assertDatabaseHas('loan_payments', [
            'loan_id'      => $advance->id,
            'amount'       => '300.00',
            'payment_type' => LoanPaymentType::FinalPay->value,
            'clearance_id' => $clearance->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // 2. The JE loan arm is reachable and finalize passes the gate
    // ─────────────────────────────────────────────────────────────

    public function test_finalize_posts_loan_settlement_je_arm_after_compute(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceDay($employee);
        $this->makeActiveLoan($employee, '500.00');

        $finalPay  = app(FinalPayService::class);
        $computed  = $finalPay->compute($clearance);
        $finalized = app(SeparationService::class)->finalize($computed->fresh(), $this->makePoster(), $finalPay);

        $this->assertSame(ClearanceStatus::Finalized->value, $finalized->status->value);
        $this->assertNotNull($finalized->journal_entry_id);

        $this->assertSame('500.00', $this->loansPayableCredits($finalized->journal_entry_id),
            'The "Settle outstanding loan from final pay" JE arm must credit what the payout absorbed.');

        $breakdown = $finalized->fresh()->final_pay_breakdown;
        $this->assertSame('500.00', $breakdown['less_loan_balance'], 'Finalize re-derivation must keep the final-pay settlement deducted.');
        $this->assertSame('409.09', $breakdown['net']);

        $je = DB::table('journal_entries')->where('id', $finalized->journal_entry_id)->firstOrFail();
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit, 'Final-pay JE must balance.');
    }

    // ─────────────────────────────────────────────────────────────
    // 3. End-to-end: separation → final pay → clearance finalize (LN-02 deadlock)
    // ─────────────────────────────────────────────────────────────

    public function test_full_separation_with_active_loan_finalizes_end_to_end(): void
    {
        app(SettingsService::class)->set('hr.separation.clearance_checklist', [
            ['department' => 'HR', 'item_key' => 'exit_interview', 'label' => 'Exit interview'],
            ['department' => 'FIN', 'item_key' => 'accountability', 'label' => 'Accountability'],
        ], 'hr');

        $employee = $this->makeEmployee();
        $actor    = $this->makePoster();
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceDay($employee);
        $loan = $this->makeActiveLoan($employee, '500.00');

        $separation = app(SeparationService::class);
        $clearance  = $separation->initiate($employee, [
            'separation_reason' => SeparationReason::Resigned->value,
            'separation_date'   => '2026-05-31',
        ], $actor);

        $finalPay = app(FinalPayService::class);
        $finalPay->compute($clearance);

        $clearance = $separation->signItem($clearance->fresh(), 'exit_interview', $actor);
        $clearance = $separation->signItem($clearance, 'accountability', $actor);
        $this->assertSame(ClearanceStatus::Completed, $clearance->status);

        // Before this fix the gate deadlocked here forever: balance > 0 with
        // no in-system way to settle. Compute settled it, so finalize passes.
        $finalized = $separation->finalize($clearance->fresh(), $actor, $finalPay);

        $this->assertSame(ClearanceStatus::Finalized->value, $finalized->status->value);
        $this->assertSame(EmployeeStatus::Resigned, $employee->fresh()->status);
        $this->assertSame('paid', $loan->fresh()->status->value);
        $this->assertSame('0.00', (string) $loan->fresh()->balance);
        $this->assertDatabaseHas('loan_payments', [
            'loan_id'      => $loan->id,
            'payment_type' => LoanPaymentType::FinalPay->value,
            'clearance_id' => $clearance->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Clamp: residue keeps the gate blocking until settled manually
    // ─────────────────────────────────────────────────────────────

    public function test_residue_beyond_earnings_blocks_until_manual_payment_settles_it(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceDay($employee);
        $loan = $this->makeActiveLoan($employee, '5000.00');

        $finalPay = app(FinalPayService::class);
        $computed = $finalPay->compute($clearance);

        // The payout absorbs only the 909.09 of earnings; the residue stays.
        $payment = LoanPayment::query()->where('loan_id', $loan->id)->sole();
        $this->assertSame('909.09', (string) $payment->amount);
        $this->assertSame('0.00', $computed->final_pay_breakdown['net']);
        $loan->refresh();
        $this->assertSame('active', $loan->status->value);
        $this->assertSame('4090.91', (string) $loan->balance);

        $poster = $this->makePoster();
        $this->actingAs($poster)
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outstanding_loans']);

        // The LN-02 remedy: HR settles the residue outside payroll.
        $this->actingAs(User::factory()->create(['role_id' => Role::where('slug', 'hr_officer')->value('id')]))
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", [
                'amount'       => '4090.91',
                'payment_date' => now()->toDateString(),
                'remarks'      => 'Residual balance settled at separation',
            ])
            ->assertStatus(201);
        $this->assertSame('paid', $loan->fresh()->status->value);

        $this->actingAs($poster)
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(200);

        $this->assertSame(ClearanceStatus::Finalized, $clearance->fresh()->status);

        // Only what final pay absorbed is credited — the external payment is
        // not deducted again.
        $jeId = (int) $clearance->fresh()->journal_entry_id;
        $this->assertSame('909.09', $this->loansPayableCredits($jeId));
    }

    // ─────────────────────────────────────────────────────────────
    // 5. Pending loans: not settleable, still gated, remedy is cancel
    // ─────────────────────────────────────────────────────────────

    public function test_pending_loan_is_not_settled_and_blocks_until_cancelled(): void
    {
        $employee  = $this->makeEmployee();
        $clearance = $this->makeClearance($employee);
        $this->seedOpenPayrollPeriod();
        $this->seedAttendanceDay($employee);

        $loan = EmployeeLoan::factory()->pending()->create([
            'employee_id'           => $employee->id,
            'loan_type'             => 'company_loan',
            'principal'             => '500.00',
            'interest_rate'         => '0.00',
            'monthly_amortization'  => '500.00',
            'total_paid'            => '0.00',
            'balance'               => '500.00',
            'pay_periods_total'     => 1,
            'pay_periods_remaining' => 1,
        ]);

        $finalPay = app(FinalPayService::class);
        $computed = $finalPay->compute($clearance);

        $this->assertSame('500.00', $computed->final_pay_breakdown['less_loan_balance'], 'Pending loans stay in the deduction.');
        $this->assertSame(0, LoanPayment::query()->where('loan_id', $loan->id)->count(), 'A pending loan was never disbursed, so nothing may be collected.');

        $poster = $this->makePoster();
        $this->actingAs($poster)
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outstanding_loans']);

        app(LoanService::class)->cancel($loan, User::factory()->create([
            'role_id' => Role::where('slug', 'hr_officer')->value('id'),
        ]));

        $this->actingAs($poster)
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(200);

        $this->assertSame('cancelled', $loan->fresh()->status->value);
        $jeId = (int) $clearance->fresh()->journal_entry_id;
        $this->assertSame('0.00', $this->loansPayableCredits($jeId), 'A cancelled pending loan contributes nothing to the JE.');
    }
}
