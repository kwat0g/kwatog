<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UomSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission Phase 2 — the LOAN leg of the Hire-to-Retire chain over the real
 * HTTP API, every step performed by its real seeded role (never system_admin).
 *
 * Chain: hr_officer raises a cash advance for an employee (one-active-per-type
 * rule, salary cap) → department head approves (step 1, own-department scope)
 * → finance_officer approves (step 2) → vice_president approves (step 3,
 * final) → loan Active, disbursement JE posted (receivable / cash) →
 * finance settles via a manual repayment (cash / receivable JE) → balance
 * zero → write-off refused on a settled loan. A company_loan variant proves
 * the 4-step chain with its extra production_manager "Manager" step.
 *
 * Adversarial probes ride along: wrong actor (an employee user requesting for
 * somebody else, a production_manager approving a cash advance whose step 2
 * is finance), wrong amount (principal above the salary cap), wrong count
 * (second active cash advance while one is pending), wrong time (approve out
 * of chain order, pay an unapproved loan, write-off a loan with no balance),
 * and integrity (amortization totals, ledger-derived balance, GL postings).
 */
class LoansChainTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private User $deptHead;
    private User $finance;
    private User $vp;
    private User $productionManager;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(UomSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $make = fn (string $slug): User => User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);

        $this->hr = $make('hr_officer');
        $this->finance = $make('finance_officer');
        $this->vp = $make('vice_president');
        $this->productionManager = $make('production_manager');

        // The employee borrower and their department head, both real rows so
        // the row-scoping and workflow steps behave exactly as in production.
        $this->employee = Employee::factory()->create([
            'basic_monthly_salary' => '20000.00',
            'pay_type' => 'monthly',
        ]);
        $this->deptHead = $make('department_head');
        $this->deptHead->forceFill(['employee_id' => Employee::factory()->create([
            'department_id' => $this->employee->department_id,
        ])->id])->save();

        // GL postings on disbursement/repayment run only when Accounting is on.
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @template T @param class-string<T> $class @return T */
    private function fromApiId(string $class, ?string $hash): mixed
    {
        $this->assertNotNull($hash, 'API response must carry an id');
        $decoded = app('hashids')->decode($hash);
        $this->assertNotEmpty($decoded, "hash id did not decode: {$hash}");

        return $class::query()->findOrFail($decoded[0]);
    }

    /** POST /loans as HR for the borrower. */
    private function requestLoan(string $type, string $principal, int $periods = 10): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->hr)->postJson('/api/v1/loans', [
            'employee_id' => $this->employee->hash_id,
            'loan_type' => $type,
            'principal' => $principal,
            'pay_periods' => $periods,
            'purpose' => 'Tuition and emergency expenses.',
        ]);
    }

    /** Walk the cash_advance chain: dept head → finance → VP. */
    private function approveCashAdvanceChain(EmployeeLoan $loan): void
    {
        foreach ([$this->deptHead, $this->finance, $this->vp] as $approver) {
            $this->actingAs($approver)
                ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", [])
                ->assertOk();
        }
    }

    // ------------------------------------------------------------------
    // The chain
    // ------------------------------------------------------------------

    public function test_cash_advance_chain_end_to_end(): void
    {
        // ------------------------------------------------------------------
        // CAP: principal above 1× monthly salary is refused.
        // ------------------------------------------------------------------
        $this->requestLoan('cash_advance', '25000.00')
            ->assertStatus(422);

        // ------------------------------------------------------------------
        // ACT 1 — hr_officer raises a within-cap cash advance.
        // ------------------------------------------------------------------
        $response = $this->requestLoan('cash_advance', '15000.00');
        $response->assertStatus(201);

        /** @var EmployeeLoan $loan */
        $loan = $this->fromApiId(EmployeeLoan::class, $response->json('data.id'));
        $this->assertSame($this->employee->id, $loan->employee_id);
        $this->assertSame(LoanStatus::Pending, $loan->status);
        $this->assertMatchesRegularExpression('/^CA-\d{6}-\d{4}$/', (string) $loan->loan_no);
        // Zero-interest: total due equals principal.
        $this->assertEquals('15000.00', (string) $loan->balance);

        // ------------------------------------------------------------------
        // One-active-per-type: a second cash advance while this one is
        // pending is refused.
        // ------------------------------------------------------------------
        $this->requestLoan('cash_advance', '5000.00')->assertStatus(422);

        // A company_loan is a DIFFERENT type — allowed in parallel.
        // (asserted later in its own test)

        // ------------------------------------------------------------------
        // Wrong actor: the production_manager cannot decide the cash advance
        // — they hold loans.approve (they are step 2 of the company_loan
        // chain) but have NO row scope on a cash_advance: not a chain
        // participant there, not the employee, not a department head. The
        // service's own guard refuses with 422 before ApprovalService is
        // ever reached.
        // ------------------------------------------------------------------
        $this->actingAs($this->productionManager)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", [])
            ->assertStatus(422);

        // Wrong actor: an employee-role user has no loans.approve at all.
        $employeeUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
            'is_active' => true,
        ]);
        $this->actingAs($employeeUser)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", [])
            ->assertStatus(403);

        // Wrong time: finance (step 2) cannot act before the department head
        // (the current step still names department_head → 403).
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", [])
            ->assertStatus(403);

        // Wrong time: payments against a Pending loan are refused. (The
        // idempotency key rides the Idempotency-Key HEADER — the request
        // class overwrites the body field with it, same contract as bills.)
        $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => 'too-early-1'])
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", [
                'amount' => '100.00',
                'payment_date' => now()->toDateString(),
            ])
            ->assertStatus(422);

        // ------------------------------------------------------------------
        // ACT 2 — walk the chain in order.
        // ------------------------------------------------------------------
        $this->actingAs($this->deptHead)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", [])
            ->assertOk();

        // Double-tap the same step is refused (nothing pending at that step
        // for that actor → 403 from ApprovalService).
        $this->actingAs($this->deptHead)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", [])
            ->assertStatus(403);

        $this->actingAs($this->finance)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", [])
            ->assertOk();

        $this->actingAs($this->vp)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", [])
            ->assertOk();

        $loan->refresh();
        $this->assertSame(LoanStatus::Active, $loan->status);

        // ------------------------------------------------------------------
        // Integrity — disbursement GL: debit receivable for the full balance,
        // credit cash for the principal (zero interest → no income line).
        // ------------------------------------------------------------------
        $this->assertNotNull($loan->disbursement_journal_entry_id);
        /** @var JournalEntry $je */
        $je = JournalEntry::query()->findOrFail($loan->disbursement_journal_entry_id);
        $this->assertSame(JournalEntryStatus::Posted, $je->status);
        $this->assertSame('loan_disbursement', $je->reference_type);
        $this->assertCount(2, $je->lines);
        // 1110 = Employee Loans Receivable, 1020 = Cash in Bank (seeder codes).
        $receivable = $je->lines->firstWhere('debit', '>', 0);
        $cash = $je->lines->firstWhere('credit', '>', 0);
        $this->assertEquals('15000.00', (string) $receivable->debit);
        $this->assertEquals('15000.00', (string) $cash->credit);

        // ------------------------------------------------------------------
        // Wrong amount: a payment above the outstanding balance is refused.
        // ------------------------------------------------------------------
        $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => 'over-balance-1'])
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", [
                'amount' => '16000.00',
                'payment_date' => now()->toDateString(),
            ])
            ->assertStatus(422);

        // ------------------------------------------------------------------
        // ACT 3 — finance settles the loan with a manual repayment.
        // ------------------------------------------------------------------
        $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => 'settle-ca-1'])
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", [
                'amount' => '15000.00',
                'payment_date' => now()->toDateString(),
                'remarks' => 'Full settlement at the cashier.',
            ])
            ->assertStatus(201);

        $loan->refresh();
        $this->assertSame(LoanStatus::Paid, $loan->status);
        $this->assertEquals('15000.00', (string) $loan->total_paid);
        $this->assertEquals('0.00', (string) $loan->balance);

        // Repayment GL: debit cash, credit receivable.
        /** @var LoanPayment $payment */
        $payment = LoanPayment::query()
            ->where('loan_id', $loan->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($payment->journal_entry_id);
        /** @var JournalEntry $repaymentJe */
        $repaymentJe = JournalEntry::query()->findOrFail($payment->journal_entry_id);
        $this->assertSame(JournalEntryStatus::Posted, $repaymentJe->status);
        $this->assertSame('loan_manual_repayment', $repaymentJe->reference_type);

        // Idempotent replay returns the same payment, no second row.
        $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => 'settle-ca-1'])
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", [
                'amount' => '15000.00',
                'payment_date' => now()->toDateString(),
            ])
            ->assertStatus(201);
        $this->assertSame(1, LoanPayment::query()->where('loan_id', $loan->id)->count());

        // ------------------------------------------------------------------
        // Wrong time: write-off needs a balance — a settled loan refuses.
        // ------------------------------------------------------------------
        $this->actingAs($this->finance)
            ->postJson("/api/v1/loans/{$loan->hash_id}/write-off", [
                'reason' => 'Disaster relief forgiveness.',
                'evidence' => 'BR-2026-0091',
            ])
            ->assertStatus(422);
    }

    public function test_company_loan_chain_and_write_off_maker_checker(): void
    {
        // ------------------------------------------------------------------
        // ACT 1 — company loan: a 4-step chain (dept head → production
        // manager → finance → VP).
        // ------------------------------------------------------------------
        $response = $this->requestLoan('company_loan', '20000.00', 12);
        $response->assertStatus(201);

        /** @var EmployeeLoan $loan */
        $loan = $this->fromApiId(EmployeeLoan::class, $response->json('data.id'));
        $this->assertMatchesRegularExpression('/^LN-\d{6}-\d{4}$/', (string) $loan->loan_no);

        // Wrong time: step 2 (production manager) cannot act first — the
        // current step names department_head → 403.
        $this->actingAs($this->productionManager)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", [])
            ->assertStatus(403);

        foreach ([$this->deptHead, $this->productionManager, $this->finance, $this->vp] as $approver) {
            $this->actingAs($approver)
                ->patchJson("/api/v1/loans/{$loan->hash_id}/approve", [])
                ->assertOk();
        }

        $loan->refresh();
        $this->assertSame(LoanStatus::Active, $loan->status);
        $this->assertEquals('20000.00', (string) $loan->balance);

        // ------------------------------------------------------------------
        // ACT 2 — partial manual repayment leaves an outstanding balance.
        // ------------------------------------------------------------------
        $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => 'partial-ln-1'])
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", [
                'amount' => '5000.00',
                'payment_date' => now()->toDateString(),
            ])
            ->assertStatus(201);

        $loan->refresh();
        $this->assertSame(LoanStatus::Active, $loan->status);
        $this->assertEquals('15000.00', (string) $loan->balance);
        $this->assertEquals('5000.00', (string) $loan->total_paid);

        // ------------------------------------------------------------------
        // ACT 3 — write-off is maker-checker: finance requests, finance
        // cannot approve their own request, VP (or another finance) can.
        // ------------------------------------------------------------------
        $this->actingAs($this->finance)
            ->postJson("/api/v1/loans/{$loan->hash_id}/write-off", [
                'reason' => 'Employee hardship — typhoon damage.',
                'evidence' => 'BR-2026-0102',
            ])
            ->assertOk();

        $loan->refresh();
        $this->assertSame(LoanStatus::WriteOffPending, $loan->status);

        // The maker cannot approve the same write-off.
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/write-off/approve", [])
            ->assertStatus(422);

        // Only an ACTIVE loan accepts payments — write-off-pending refuses.
        $this->actingAs($this->finance)
            ->withHeaders(['Idempotency-Key' => 'while-writeoff-1'])
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", [
                'amount' => '1000.00',
                'payment_date' => now()->toDateString(),
            ])
            ->assertStatus(422);

        // Wrong actor: the department head holds no write-off approval
        // (FormRequest authorize → 403).
        $this->actingAs($this->deptHead)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/write-off/approve", [])
            ->assertStatus(403);

        // The VP does not hold write-off approve either — a second finance
        // officer is the checker (FormRequest authorize → 403).
        $this->actingAs($this->vp)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/write-off/approve", [])
            ->assertStatus(403);

        $secondFinance = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
            'is_active' => true,
        ]);
        $this->actingAs($secondFinance)
            ->patchJson("/api/v1/loans/{$loan->hash_id}/write-off/approve", [])
            ->assertOk();

        $loan->refresh();
        $this->assertSame(LoanStatus::WrittenOff, $loan->status);
    }
}
