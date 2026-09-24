<?php

declare(strict_types=1);

namespace App\Modules\Loans\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Models\WorkflowDefinition;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Services\AccountingAccountPolicyService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanPaymentType;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Loans\Policies\LoanAccessPolicy;
use App\Modules\Loans\Events\LoanDecided;
use App\Modules\Loans\Events\LoanSubmitted;
use App\Modules\Loans\Support\LoanRate;
use App\Modules\Loans\Support\LoanStateMachine;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LoanService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly AmortizationService $amortization,
        private readonly ApprovalService $approvals,
        private readonly SettingsService $settings,
        private readonly LoanAccessPolicy $access,
        private readonly LoanStateMachine $stateMachine,
        private readonly PayrollPeriodService $payrollPeriods,
        private readonly AccountingAccountPolicyService $accountPolicies,
        private readonly JournalEntryService $journals,
    ) {}

    /** @return array<int, array{value:string,label:string,interest_rate:string,interest_rate_percent:string,approval_steps:int}> */
    public function types(): array
    {
        $workflows = WorkflowDefinition::query()
            ->whereIn('workflow_type', LoanType::values())
            ->where('is_active', true)
            ->get()
            ->keyBy('workflow_type');

        return collect(LoanType::cases())
            ->filter(fn (LoanType $type) => $type->isSupported() && $workflows->has($type->value))
            ->map(function (LoanType $type) use ($workflows) {
                $workflow = $workflows->get($type->value);
                $rate = $this->interestRateFor($type);
                return [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'interest_rate' => $rate,
                    'interest_rate_percent' => LoanRate::percent($rate),
                    'approval_steps' => count($workflow->steps ?? []),
                ];
            })
            ->values()
            ->all();
    }

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $q = EmployeeLoan::query()->with([
            'employee:id,employee_no,first_name,middle_name,last_name,suffix,department_id',
            'employee.user:id,employee_id',
            'approvalRecords',
        ]);
        if (!empty($filters['employee_id'])) {
            $empId = \App\Common\Support\HashIdFilter::decode(
                $filters['employee_id'], \App\Modules\HR\Models\Employee::class,
            );
            if ($empId) $q->where('employee_id', $empId);
        }
        if (!empty($filters['loan_type'])) $q->where('loan_type', $filters['loan_type']);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('loan_no', SearchOperator::like(), SearchOperator::contains($term))
                   ->orWhereHas('employee', fn ($e) => $e->where('first_name', SearchOperator::like(), SearchOperator::contains($term))
                       ->orWhere('last_name', SearchOperator::like(), SearchOperator::contains($term))
                       ->orWhere('employee_no', SearchOperator::like(), SearchOperator::contains($term)));
            });
        }

        // Permission grants the operation; this policy grants the rows.
        $q = $this->access->visibleTo($q, $user);

        return $q->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(EmployeeLoan $loan): EmployeeLoan
    {
        return $loan->load(['employee.user:id,employee_id', 'payments', 'approvalRecords.approver:id,name']);
    }

    /** @return array{principal_max:string, has_active:bool, max_pay_periods:int} */
    public function limitsFor(Employee $employee, LoanType $type): array
    {
        $this->assertSupportedType($type);
        $multiplier = $this->decimalSetting("loans.{$type->value}.max_salary_multiplier");
        // Monthly equivalent, whichever pay type: the model reconciles the two so
        // a semi-monthly employee's cap is not computed off a half-month figure.
        $monthly = $employee->monthlyEquivalentSalary();
        if ($monthly === null || Money::lte($monthly, '0.00')) {
            throw new BusinessRuleException('An authoritative employee pay rate is required before loan limits can be calculated.');
        }
        $max = Money::mul($monthly, $multiplier);
        $hasActive = EmployeeLoan::query()
            ->where('employee_id', $employee->id)
            ->where('loan_type', $type->value)
            ->whereIn('status', [LoanStatus::Pending->value, LoanStatus::Active->value])
            ->exists();

        return [
            'principal_max' => Money::round2($max),
            'has_active' => $hasActive,
            'max_pay_periods' => $this->settings->requiredInt('loans.max_pay_periods', 1, 120),
        ];
    }

    public function request(int $employeeId, LoanType $type, array $data): EmployeeLoan
    {
        $this->assertSupportedType($type);

        return DB::transaction(function () use ($employeeId, $type, $data) {
            $principal = $this->normalizeMoneyAmount($data['principal'] ?? null, 'Principal');

            // Serialize requests for one employee so two concurrent portal
            // submissions cannot both pass the active-loan check.
            $employee = Employee::query()->lockForUpdate()->findOrFail($employeeId);

            // One active loan per type rule.
            $hasActive = EmployeeLoan::query()
                ->where('employee_id', $employeeId)
                ->where('loan_type', $type->value)
                ->whereIn('status', [LoanStatus::Pending->value, LoanStatus::Active->value])
                ->exists();
            if ($hasActive) {
                throw new BusinessRuleException("An active or pending {$type->value} already exists for this employee.");
            }

            // Cap check.
            $limits = $this->limitsFor($employee, $type);
            if (bccomp($principal, $limits['principal_max'], 2) > 0) {
                throw new BusinessRuleException('Principal exceeds maximum of '.app(\App\Common\Services\CurrencyDisplayService::class)->format($limits['principal_max'])." for {$type->value}.");
            }

            $sequenceKey = $type === LoanType::CashAdvance ? 'cash_advance' : 'loan';
            $loanNo = $this->sequences->generate($sequenceKey);

            $periods = (int) $data['pay_periods'];
            $interestRate = $this->interestRateFor($type);
            $schedule = $this->amortization->generateWithInterest(
                $principal,
                $interestRate,
                $periods,
            );
            $perPeriod = $schedule[0]['amount'];
            $totalDue = array_reduce(
                $schedule,
                fn (string $total, array $row) => bcadd($total, $row['amount'], 2),
                '0.00',
            );
            $workflow = WorkflowDefinition::query()
                ->where('workflow_type', $type->workflowType())
                ->where('is_active', true)
                ->first();
            if (! $workflow) {
                throw new BusinessRuleException("No approval workflow is configured for {$type->label()}.");
            }
            $chainSize = count($workflow->steps ?? []);

            $loan = EmployeeLoan::create([
                'loan_no'                => $loanNo,
                'employee_id'            => $employeeId,
                'loan_type'              => $type->value,
                'principal'              => $principal,
                'interest_rate'          => $interestRate,
                'monthly_amortization'   => $perPeriod,
                'total_paid'             => 0,
                'balance'                => $totalDue,
                'pay_periods_total'      => $periods,
                'pay_periods_remaining'  => $periods,
                'approval_chain_size'    => $chainSize,
                'purpose'                => $data['purpose'] ?? null,
            ]);
            // status is non-fillable (service-only mutation); forceFill + save.
            $loan->forceFill(['status' => LoanStatus::Pending->value])->save();

            $this->approvals->submit($loan, $type->workflowType(), $principal);

            app(OutboxService::class)->record(
                new LoanSubmitted($loan->fresh(['employee'])),
            );

            return $loan->load('employee');
        });
    }

    public function approve(EmployeeLoan $loan, User $user, ?string $remarks = null): EmployeeLoan
    {
        return DB::transaction(function () use ($loan, $user, $remarks) {
            $authoritative = EmployeeLoan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($authoritative->status !== LoanStatus::Pending) {
                throw new BusinessRuleException('Only pending loans can be approved.');
            }
            if (! $user->hasPermission('loans.approve') || ! $this->access->canDecide($user, $authoritative)) {
                throw new BusinessRuleException('You do not have permission to decide this loan within your row scope.');
            }
            $this->approvals->approve($authoritative, $user, $remarks);

            $isFinal = $this->approvals->isFullyApproved($authoritative);
            if ($isFinal) {
                // Single save → single audit row for one logical action.
                $authoritative->fill(['start_date' => now()->toDateString()]);
                $this->stateMachine->transition($authoritative, LoanStatus::Active);
                $authoritative->save();
                $this->postDisbursement($authoritative, $user);
            }

            $loan = $authoritative->fresh(['employee', 'payments']);
            if ($isFinal) {
                app(OutboxService::class)->record(
                    new LoanDecided($loan->fresh(['employee']), true),
                );
            }
            return $loan;
        });
    }

    /**
     * T1.7 — Bulk approve loan applications. Per-row try/catch.
     *
     * The failure rows are echoed straight back to the caller, so they must
     * carry the obfuscated identifier the caller submitted — never the decoded
     * primary key. A raw `employee_loans.id` in a response body is an
     * existence oracle, and since HashIdFilter::decode accepts bare integers a
     * caller can probe with PKs directly.
     *
     * @param array<int, int> $ids
     * @return array{approved: array<int, EmployeeLoan>, failed: array<int, array{id:string, reason:string}>}
     */
    public function bulkApprove(array $ids, User $approver, ?string $remarks = null): array
    {
        $approved = [];
        $failed   = [];

        foreach ($ids as $id) {
            $reference = app('hashids')->encode($id);
            try {
                $loan = EmployeeLoan::query()->find($id);
                if (! $loan) {
                    $failed[] = ['id' => $reference, 'reason' => 'Not found.'];
                    continue;
                }
                $approved[] = $this->approve($loan, $approver, $remarks);
            } catch (\Throwable $e) {
                $failed[] = ['id' => $reference, 'reason' => $e->getMessage()];
            }
        }
        return ['approved' => $approved, 'failed' => $failed];
    }

    public function reject(EmployeeLoan $loan, User $user, string $reason): EmployeeLoan
    {
        return DB::transaction(function () use ($loan, $user, $reason) {
            $authoritative = EmployeeLoan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($authoritative->status !== LoanStatus::Pending) {
                throw new BusinessRuleException('Only pending loans can be rejected.');
            }
            if (! $user->hasPermission('loans.approve') || ! $this->access->canDecide($user, $authoritative)) {
                throw new BusinessRuleException('You do not have permission to decide this loan within your row scope.');
            }
            $this->approvals->reject($authoritative, $user, $reason);
            $this->stateMachine->transition($authoritative, LoanStatus::Rejected);
            $authoritative->save();
            $loan = $authoritative->fresh(['employee']);
            app(OutboxService::class)->record(
                new LoanDecided($loan->fresh(['employee']), false),
            );
            return $loan;
        });
    }

    public function cancel(EmployeeLoan $loan, User $user): EmployeeLoan
    {
        return DB::transaction(function () use ($loan, $user) {
            if (! $user->hasPermission('loans.write_off') || ! $this->access->canDecide($user, $loan)) {
                throw new BusinessRuleException('You do not have permission to cancel this loan within your row scope.');
            }

            $authoritative = EmployeeLoan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($authoritative->status !== LoanStatus::Pending) {
                throw new BusinessRuleException('Active loans cannot be cancelled; use the approved write-off or settlement workflow.');
            }
            if (! $this->access->canDecide($user, $authoritative)) {
                throw new BusinessRuleException('You do not have permission to cancel this loan within your row scope.');
            }
            $this->retirePendingApprovalRecords($authoritative);

            $this->stateMachine->transition($authoritative, LoanStatus::Cancelled);
            $authoritative->save();
            return $authoritative->fresh(['employee', 'payments']);
        });
    }

    public function requestWriteOff(EmployeeLoan $loan, User $maker, string $reason, string $evidence): EmployeeLoan
    {
        return DB::transaction(function () use ($loan, $maker, $reason, $evidence) {
            if (! $maker->hasPermission('loans.write_off.request') || ! $this->access->canDecide($maker, $loan)) {
                throw new BusinessRuleException('You do not have Finance permission to request this loan write-off within your row scope.');
            }

            $authoritative = EmployeeLoan::query()->lockForUpdate()->findOrFail($loan->id);
            if (! $this->access->canDecide($maker, $authoritative)) {
                throw new BusinessRuleException('You do not have permission to request this loan write-off within your row scope.');
            }
            if ($authoritative->status === LoanStatus::WriteOffPending) {
                return $authoritative->fresh(['employee']);
            }
            if ($authoritative->status !== LoanStatus::Active) {
                throw new BusinessRuleException('Only active loans can be submitted for write-off.');
            }

            $balance = $this->outstandingBalance($authoritative);
            if (Money::lte($balance, Money::zero())) {
                throw new BusinessRuleException('A loan with no outstanding balance cannot be written off.');
            }

            $reason = trim($reason);
            $evidence = trim($evidence);
            if ($reason === '' || $evidence === '') {
                throw new BusinessRuleException('A write-off reason and evidence reference are required.');
            }

            $this->stateMachine->transition($authoritative, LoanStatus::WriteOffPending);
            $authoritative->fill([
                'write_off_reason' => $reason,
                'write_off_evidence' => $evidence,
                'write_off_requested_by' => $maker->id,
                'write_off_requested_at' => now(),
            ]);
            $authoritative->save();

            return $authoritative->fresh(['employee']);
        });
    }

    public function approveWriteOff(EmployeeLoan $loan, User $checker, ?string $remarks = null): EmployeeLoan
    {
        return DB::transaction(function () use ($loan, $checker, $remarks) {
            if (! $checker->hasPermission('loans.write_off.approve') || ! $this->access->canDecide($checker, $loan)) {
                throw new BusinessRuleException('You do not have Finance permission to approve this loan write-off within your row scope.');
            }

            $authoritative = EmployeeLoan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($authoritative->status === LoanStatus::WrittenOff) {
                return $authoritative->fresh(['employee']);
            }
            if ($authoritative->status !== LoanStatus::WriteOffPending) {
                throw new BusinessRuleException('Only pending loan write-offs can be approved.');
            }
            if ((int) $authoritative->write_off_requested_by === (int) $checker->id) {
                throw new BusinessRuleException('The write-off maker cannot approve the same write-off.');
            }
            if (! $authoritative->write_off_reason || ! $authoritative->write_off_evidence) {
                throw new BusinessRuleException('A write-off reason and evidence reference are required before approval.');
            }

            $amount = $this->outstandingBalance($authoritative);
            if (Money::lte($amount, Money::zero())) {
                throw new BusinessRuleException('A loan with no outstanding balance cannot be written off.');
            }

            $expense = $this->loanAccount('accounting.accounts.loan_write_off_expense_code');
            $receivable = $this->loanAccount('accounting.accounts.loan_write_off_receivable_code');
            $je = $this->journals->create([
                'date' => now()->toDateString(),
                'description' => 'Employee loan write-off — '.$authoritative->loan_no,
                'reference_type' => 'loan_write_off',
                'reference_id' => $authoritative->id,
                'lines' => [
                    ['account_id' => $expense, 'debit' => $amount, 'credit' => Money::zero(), 'description' => 'Employee loan write-off expense'],
                    ['account_id' => $receivable, 'debit' => Money::zero(), 'credit' => $amount, 'description' => 'Remove written-off employee loan receivable'],
                ],
            ], $checker);
            $posted = $this->journals->postSystem($je, $checker->id);

            $this->stateMachine->transition($authoritative, LoanStatus::WrittenOff);
            $authoritative->fill([
                'write_off_amount' => $amount,
                'write_off_approved_by' => $checker->id,
                'write_off_approved_at' => now(),
                'write_off_journal_entry_id' => $posted->id,
                'balance' => Money::zero(),
                'pay_periods_remaining' => 0,
                'end_date' => now()->toDateString(),
                'write_off_approval_remarks' => $remarks,
            ]);
            $authoritative->save();

            return $authoritative->fresh(['employee']);
        });
    }

    public function withdraw(EmployeeLoan $loan, Employee $employee): EmployeeLoan
    {
        return DB::transaction(function () use ($loan, $employee) {
            $authoritative = EmployeeLoan::query()->lockForUpdate()->findOrFail($loan->id);
            if ((int) $authoritative->employee_id !== (int) $employee->id) {
                throw new BusinessRuleException('You may only withdraw your own loan request.');
            }
            if ($authoritative->status !== LoanStatus::Pending) {
                throw new BusinessRuleException('Only pending loans can be withdrawn.');
            }

            $this->retirePendingApprovalRecords($authoritative);
            $this->stateMachine->transition($authoritative, LoanStatus::Cancelled);
            $authoritative->save();

            return $authoritative->fresh(['employee', 'payments']);
        });
    }

    private function retirePendingApprovalRecords(EmployeeLoan $loan): void
    {
        ApprovalRecord::query()
            ->where('approvable_type', $loan->getMorphClass())
            ->where('approvable_id', $loan->getKey())
            ->where('is_current', true)
            ->whereIn('action', ['pending', 'skipped'])
            ->update(['action' => 'superseded', 'is_current' => false]);
    }

    public function recordPayment(
        EmployeeLoan $loan,
        string $amount,
        LoanPaymentType $type,
        ?int $payrollId = null,
        ?string $remarks = null,
        ?string $paymentDate = null,
        ?int $clearanceId = null,
        ?string $idempotencyKey = null,
        ?User $actor = null,
    ): LoanPayment {
        if ($type === LoanPaymentType::FinalPay && $clearanceId !== null) {
            $idempotencyKey = 'final-pay-clearance-'.$clearanceId;
        }

        return DB::transaction(function () use ($loan, $amount, $type, $payrollId, $remarks, $paymentDate, $clearanceId, $idempotencyKey, $actor) {
            if ($type === LoanPaymentType::Manual && $payrollId === null && $clearanceId === null) {
                $this->payrollPeriods->assertLoanPaymentMutable(
                    (int) $loan->employee_id,
                    $paymentDate ?? now()->toDateString(),
                );
            }

            // Loan payment serialization invariant: every path that changes a
            // loan row must make its decisions from the current row while
            // holding that row lock, then commit the payment detail and loan
            // aggregate in this same transaction.
            $authoritative = EmployeeLoan::query()->lockForUpdate()->findOrFail($loan->getKey());
            if ($idempotencyKey !== null) {
                $existing = $authoritative->payments()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            if ($authoritative->status !== LoanStatus::Active) {
                throw new BusinessRuleException('Only active loans accept payments.');
            }
            $normalizedAmount = $this->normalizeMoneyAmount($amount, 'Payment');
            $ledgerPaid = (string) $authoritative->payments()->reorder()->sum('amount');
            $currentBalance = Money::sub($this->totalDueFor($authoritative), $ledgerPaid);
            if (Money::lt($currentBalance, '0.00')) {
                $currentBalance = '0.00';
            }
            if (Money::gt($normalizedAmount, $currentBalance)) {
                throw new BusinessRuleException('Payment amount cannot exceed the outstanding loan balance.');
            }
            $now = now();

            /** @var LoanPayment $payment */
            $payment = $authoritative->payments()->create([
                'payroll_id' => $payrollId,
                'clearance_id' => $clearanceId,
                'amount' => $normalizedAmount,
                'payment_date' => $paymentDate ?? $now->toDateString(),
                'payment_type' => $type->value,
                'remarks' => $remarks,
                'idempotency_key' => $idempotencyKey,
                'created_at' => $now,
            ]);

            // Rebuild aggregates from the immutable payment ledger while the
            // authoritative loan row is locked. This also repairs a legacy
            // drifted aggregate instead of compounding it on the next write.
            $this->reconcileAggregates($authoritative);

            if ($type === LoanPaymentType::Manual) {
                $this->postManualRepayment($payment, $authoritative, $actor);
            }

            return $payment;
        });
    }

    /** Reconcile the denormalized loan summary from its immutable payment rows. */
    public function reconcileAggregates(EmployeeLoan $loan): EmployeeLoan
    {
        if (! in_array($loan->status, [LoanStatus::Active, LoanStatus::Paid], true)) {
            throw new BusinessRuleException('Only active or paid loans can be reconciled from payment history.');
        }

        $payments = $loan->payments()->reorder();
        $paid = (string) $payments->sum('amount');
        $latestPaymentDate = $loan->payments()->reorder()->max('payment_date');
        $schedule = $this->scheduleFor($loan);
        $totalDue = $this->totalDueFor($loan, $schedule);
        $balance = Money::sub($totalDue, $paid);
        if (Money::lt($balance, '0.00')) {
            $balance = '0.00';
        }
        $paidOff = Money::lte($balance, '0.00');
        $remaining = $this->remainingPeriods($schedule, $paid);
        $loan->fill([
            'total_paid' => Money::round2($paid),
            'balance' => Money::round2($balance),
            'pay_periods_remaining' => $remaining,
            'end_date' => $paidOff ? ($latestPaymentDate ?? now()->toDateString()) : null,
        ]);
        $this->stateMachine->transition($loan, $paidOff ? LoanStatus::Paid : LoanStatus::Active);
        $loan->save();
        return $loan;
    }

    /** Used by Sprint 3's PayrollCalculatorService. */
    public function activeForPayroll(int $employeeId): \Illuminate\Database\Eloquent\Collection
    {
        return EmployeeLoan::query()
            ->where('employee_id', $employeeId)
            ->where('status', LoanStatus::Active->value)
            ->get();
    }

    public function interestRateFor(LoanType $type): string
    {
        $this->assertSupportedType($type);

        return LoanRate::normalize((string) $this->requiredSetting("loans.{$type->value}.annual_interest_rate"));
    }

    private function assertSupportedType(LoanType $type): void
    {
        if (! $type->isSupported()) {
            throw new BusinessRuleException('Government loan types are not currently supported.');
        }
    }

    private function requiredSetting(string $key): mixed
    {
        $sentinel = '__missing_loan_policy_setting__';
        $value = $this->settings->get($key, $sentinel);
        if ($value === $sentinel || ! is_numeric($value)) {
            throw new BusinessRuleException("Required loan setting {$key} is not configured.");
        }

        return $value;
    }

    private function decimalSetting(string $key): string
    {
        $value = trim((string) $this->requiredSetting($key));
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/D', $value)) {
            throw new BusinessRuleException("Required loan setting {$key} is not a valid decimal.");
        }

        return rtrim(rtrim(bcadd($value, '0', 6), '0'), '.') ?: '0';
    }

    private function normalizeMoneyAmount(mixed $value, string $label): string
    {
        $raw = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $raw)) {
            throw new BusinessRuleException("{$label} must be a positive amount with no more than 2 decimal places.");
        }

        $normalized = bcadd($raw, '0', 2);
        if (Money::lte($normalized, '0.00')) {
            throw new BusinessRuleException("{$label} must be greater than zero.");
        }

        return $normalized;
    }

    private function postDisbursement(EmployeeLoan $loan, User $actor): void
    {
        if ($this->settings->get('modules.accounting', false) !== true || $loan->disbursement_journal_entry_id !== null) {
            return;
        }

        $receivable = $this->loanAccount('accounting.accounts.loan_disbursement_receivable_code');
        $cash = $this->loanAccount('accounting.accounts.loan_disbursement_cash_code');
        $interest = Money::sub((string) $loan->balance, (string) $loan->principal);
        if (Money::lt($interest, Money::zero())) {
            throw new BusinessRuleException('Loan balance cannot be below principal at disbursement.');
        }

        $lines = [
            ['account_id' => $receivable, 'debit' => (string) $loan->balance, 'credit' => Money::zero(), 'description' => 'Employee loan receivable'],
            ['account_id' => $cash, 'debit' => Money::zero(), 'credit' => (string) $loan->principal, 'description' => 'Employee loan cash disbursement'],
        ];
        if (Money::gt($interest, Money::zero())) {
            $interestIncome = $this->loanAccount('accounting.accounts.loan_disbursement_interest_income_code');
            $lines[] = ['account_id' => $interestIncome, 'debit' => Money::zero(), 'credit' => $interest, 'description' => 'Employee loan interest income'];
        }

        $je = $this->journals->create([
            'date' => now()->toDateString(),
            'description' => 'Employee loan disbursement — '.$loan->loan_no,
            'reference_type' => 'loan_disbursement',
            'reference_id' => $loan->id,
            'lines' => $lines,
        ], $actor);
        $posted = $this->journals->postSystem($je, $actor->id);
        $loan->forceFill(['disbursement_journal_entry_id' => $posted->id])->save();
    }

    private function postManualRepayment(LoanPayment $payment, EmployeeLoan $loan, ?User $actor): void
    {
        if ($this->settings->get('modules.accounting', false) !== true || $payment->journal_entry_id !== null) {
            return;
        }

        $cash = $this->loanAccount('accounting.accounts.loan_repayment_cash_code');
        $receivable = $this->loanAccount('accounting.accounts.loan_repayment_receivable_code');
        $je = $this->journals->create([
            'date' => $payment->payment_date->toDateString(),
            'description' => 'Manual employee loan repayment — '.$loan->loan_no,
            'reference_type' => 'loan_manual_repayment',
            'reference_id' => $payment->id,
            'lines' => [
                ['account_id' => $cash, 'debit' => (string) $payment->amount, 'credit' => Money::zero(), 'description' => 'Manual loan repayment received'],
                ['account_id' => $receivable, 'debit' => Money::zero(), 'credit' => (string) $payment->amount, 'description' => 'Reduce employee loan receivable'],
            ],
        ], $actor);
        $posted = $this->journals->postSystem($je, $actor?->id);
        $payment->forceFill(['journal_entry_id' => $posted->id])->save();
    }

    private function outstandingBalance(EmployeeLoan $loan): string
    {
        $paid = (string) $loan->payments()->reorder()->sum('amount');
        return Money::clampMin(Money::sub($this->totalDueFor($loan), $paid), Money::zero());
    }

    private function loanAccount(string $setting): int
    {
        try {
            return $this->accountPolicies->controlAccountIdForSetting($setting);
        } catch (\Throwable $e) {
            throw new BusinessRuleException("Loan accounting mapping {$setting} is not configured for an active leaf account.", 0, $e);
        }
    }

    /** @return array<int, array{amount:string}> */
    private function scheduleFor(EmployeeLoan $loan): array
    {
        return $this->amortization->generateWithInterest(
            (string) $loan->principal,
            (string) $loan->interest_rate,
            (int) $loan->pay_periods_total,
        );
    }

    /**
     * @param array<int, array{amount:string}>|null $schedule
     */
    private function totalDueFor(EmployeeLoan $loan, ?array $schedule = null): string
    {
        return array_reduce(
            $schedule ?? $this->scheduleFor($loan),
            static fn (string $total, array $row): string => Money::add($total, (string) $row['amount']),
            '0.00',
        );
    }

    /** @param array<int, array{amount:string}> $schedule */
    private function remainingPeriods(array $schedule, string $paid): int
    {
        $covered = '0.00';
        $remaining = 0;
        foreach ($schedule as $row) {
            $covered = Money::add($covered, (string) $row['amount']);
            if (Money::gt($covered, $paid)) {
                $remaining++;
            }
        }

        return $remaining;
    }
}
