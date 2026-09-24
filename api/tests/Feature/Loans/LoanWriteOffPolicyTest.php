<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanPaymentType;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Loans\Services\LoanService;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LoanWriteOffPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class, WorkflowSeeder::class]);
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
    }

    private function finance(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
    }

    private function loan(array $overrides = []): EmployeeLoan
    {
        return EmployeeLoan::factory()->create(array_merge([
            'principal' => '1000.00',
            'interest_rate' => '0.00',
            'monthly_amortization' => '500.00',
            'total_paid' => '0.00',
            'balance' => '1000.00',
            'pay_periods_total' => 2,
            'pay_periods_remaining' => 2,
        ], $overrides));
    }

    public function test_finance_write_off_requires_evidence_and_a_different_checker(): void
    {
        $loan = $this->loan();
        $maker = $this->finance();
        $checker = $this->finance();

        $this->actingAs($maker, 'sanctum')
            ->postJson("/api/v1/loans/{$loan->hash_id}/write-off", [
                'reason' => 'Borrower separated and recovery is not viable.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('evidence');

        $this->actingAs($maker, 'sanctum')
            ->postJson("/api/v1/loans/{$loan->hash_id}/write-off", [
                'reason' => 'Borrower separated and recovery is not viable.',
                'evidence' => 'HR clearance CLR-2026-0012; Finance case FIN-0042',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LoanStatus::WriteOffPending->value);

        $this->actingAs($maker, 'sanctum')
            ->patchJson("/api/v1/loans/{$loan->hash_id}/write-off/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The write-off maker cannot approve the same write-off.');

        $this->assertSame(LoanStatus::WriteOffPending, $loan->fresh()->status);

        $this->actingAs($checker, 'sanctum')
            ->patchJson("/api/v1/loans/{$loan->hash_id}/write-off/approve", ['remarks' => 'Evidence reviewed.'])
            ->assertOk()
            ->assertJsonPath('data.status', LoanStatus::WrittenOff->value)
            ->assertJsonPath('data.balance', '0.00');

        $loan->refresh();
        $journal = JournalEntry::query()->whereKey($loan->write_off_journal_entry_id)->with('lines.account')->firstOrFail();
        $this->assertSame('posted', $journal->status->value);
        $this->assertSame('1000.00', (string) $journal->total_debit);
        $this->assertSame('1000.00', (string) $journal->total_credit);
        $this->assertSame(['6130', '1110'], $journal->lines->map(fn ($line) => $line->account->code)->all());
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => EmployeeLoan::class,
            'model_id' => $loan->id,
            'action' => 'updated',
        ]);
    }

    public function test_write_off_approval_is_idempotent_and_terminal(): void
    {
        $loan = $this->loan();
        $maker = $this->finance();
        $checker = $this->finance();

        $this->actingAs($maker, 'sanctum')->postJson("/api/v1/loans/{$loan->hash_id}/write-off", [
            'reason' => 'No recoverable assets remain after separation.',
            'evidence' => 'Clearance CLR-2026-0013',
        ])->assertOk();

        $this->actingAs($checker, 'sanctum')->patchJson("/api/v1/loans/{$loan->hash_id}/write-off/approve")
            ->assertOk();
        $journalId = $loan->fresh()->write_off_journal_entry_id;

        $this->actingAs($checker, 'sanctum')->patchJson("/api/v1/loans/{$loan->hash_id}/write-off/approve")
            ->assertOk()
            ->assertJsonPath('data.status', LoanStatus::WrittenOff->value);

        $this->assertSame($journalId, $loan->fresh()->write_off_journal_entry_id);
        $this->assertSame(1, JournalEntry::query()->where('reference_type', 'loan_write_off')->where('reference_id', $loan->id)->count());
        $this->assertSame(2, JournalEntry::query()->where('reference_type', 'loan_write_off')->where('reference_id', $loan->id)->first()->lines()->count());
    }

    public function test_non_finance_cannot_request_a_write_off(): void
    {
        $loan = $this->loan();
        $departmentHead = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
        ]);

        $this->actingAs($departmentHead, 'sanctum')
            ->postJson("/api/v1/loans/{$loan->hash_id}/write-off", [
                'reason' => 'This should not be accepted.',
                'evidence' => 'Unauthorized case',
            ])
            ->assertForbidden();
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    public function test_manual_repayment_posts_a_balanced_entry_once(): void
    {
        $loan = $this->loan();
        $finance = $this->finance();
        $headers = ['Idempotency-Key' => 'loan-repayment-policy-1'];
        $payload = [
            'amount' => '250.00',
            'payment_date' => '2026-09-24',
            'remarks' => 'Cash receipt FIN-0043',
        ];

        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", $payload, $headers)
            ->assertCreated();
        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/loans/{$loan->hash_id}/payments", $payload, $headers)
            ->assertCreated();

        $payment = LoanPayment::query()->where('loan_id', $loan->id)->sole();
        $this->assertNotNull($payment->journal_entry_id);
        $this->assertSame(1, JournalEntry::query()->where('reference_type', 'loan_manual_repayment')->where('reference_id', $payment->id)->count());
        $this->assertSame('750.00', (string) $loan->fresh()->balance);
    }

    public function test_write_off_does_not_mutate_a_finalized_payroll_row(): void
    {
        $loan = $this->loan();
        $period = PayrollPeriod::factory()->create(['status' => PayrollPeriodStatus::Finalized->value]);
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $loan->employee_id,
            'loan_deductions' => '250.00',
        ]);
        $maker = $this->finance();
        $checker = $this->finance();

        $this->actingAs($maker, 'sanctum')->postJson("/api/v1/loans/{$loan->hash_id}/write-off", [
            'reason' => 'Finalized payroll is preserved while the receivable is written off.',
            'evidence' => 'Finance case FIN-0044',
        ])->assertOk();
        $this->actingAs($checker, 'sanctum')->patchJson("/api/v1/loans/{$loan->hash_id}/write-off/approve")
            ->assertOk();

        $this->assertDatabaseHas('payrolls', [
            'id' => $payroll->id,
            'loan_deductions' => '250.00',
        ]);
    }

    public function test_approved_loan_disbursement_uses_configured_receivable_and_cash_accounts(): void
    {
        $employee = Employee::factory()->create();
        $loan = app(LoanService::class)->request($employee->id, LoanType::CashAdvance, [
            'principal' => '1000.00',
            'pay_periods' => 2,
            'purpose' => 'Disbursement mapping test',
        ]);
        $finance = $this->finance();
        $vp = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'vice_president')->value('id'),
        ]);
        $departmentHead = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => Employee::factory()->create(['department_id' => $employee->department_id])->id,
        ]);

        app(LoanService::class)->approve($loan, $departmentHead);
        app(LoanService::class)->approve($loan->fresh(), $finance);
        $active = app(LoanService::class)->approve($loan->fresh(), $vp);

        $this->assertSame(LoanStatus::Active, $active->status);
        $journal = JournalEntry::query()->whereKey($active->disbursement_journal_entry_id)->with('lines.account')->firstOrFail();
        $this->assertSame(['1110', '1020'], $journal->lines->map(fn ($line) => $line->account->code)->all());
        $this->assertSame((string) $journal->total_debit, (string) $journal->total_credit);
    }
}
