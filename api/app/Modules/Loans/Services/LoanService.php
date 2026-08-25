<?php

declare(strict_types=1);

namespace App\Modules\Loans\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Models\WorkflowDefinition;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
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
            ->filter(fn (LoanType $type) => $workflows->has($type->value))
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
                $qq->where('loan_no', 'ilike', "%{$term}%")
                   ->orWhereHas('employee', fn ($e) => $e->where('first_name', 'ilike', "%{$term}%")
                       ->orWhere('last_name', 'ilike', "%{$term}%")
                       ->orWhere('employee_no', 'ilike', "%{$term}%"));
            });
        }

        // Permission grants the operation; this policy grants the rows.
        $q = $this->access->visibleTo($q, $user);

        return $q->with(['employee:id,employee_no,first_name,last_name,department_id'])
            ->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(EmployeeLoan $loan): EmployeeLoan
    {
        return $loan->load(['employee', 'payments', 'approvalRecords.approver:id,name']);
    }

    /** @return array{principal_max:string, has_active:bool, max_pay_periods:int} */
    public function limitsFor(Employee $employee, LoanType $type): array
    {
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
     * @param array<int, int> $ids
     * @return array{approved: array<int, EmployeeLoan>, failed: array<int, array{id:int, reason:string}>}
     */
    public function bulkApprove(array $ids, User $approver, ?string $remarks = null): array
    {
        $approved = [];
        $failed   = [];

        foreach ($ids as $id) {
            try {
                $loan = EmployeeLoan::query()->find($id);
                if (! $loan) {
                    $failed[] = ['id' => $id, 'reason' => 'Not found.'];
                    continue;
                }
                $approved[] = $this->approve($loan, $approver, $remarks);
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
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
            $this->stateMachine->transition($authoritative, LoanStatus::Cancelled);
            $authoritative->save();
            return $authoritative->fresh(['employee', 'payments']);
        });
    }

    public function recordPayment(
        EmployeeLoan $loan,
        string $amount,
        LoanPaymentType $type,
        ?int $payrollId = null,
        ?string $remarks = null,
    ): LoanPayment {
        return DB::transaction(function () use ($loan, $amount, $type, $payrollId, $remarks) {
            // Loan payment serialization invariant: every path that changes a
            // loan row must make its decisions from the current row while
            // holding that row lock, then commit the payment detail and loan
            // aggregate in this same transaction.
            $authoritative = EmployeeLoan::query()->lockForUpdate()->findOrFail($loan->getKey());
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
                'amount' => $normalizedAmount,
                'payment_date' => $now->toDateString(),
                'payment_type' => $type->value,
                'remarks' => $remarks,
                'created_at' => $now,
            ]);

            // Rebuild aggregates from the immutable payment ledger while the
            // authoritative loan row is locked. This also repairs a legacy
            // drifted aggregate instead of compounding it on the next write.
            $this->reconcileAggregates($authoritative);

            return $payment;
        });
    }

    /** Reconcile the denormalized loan summary from its immutable payment rows. */
    public function reconcileAggregates(EmployeeLoan $loan, ?string $asOf = null): EmployeeLoan
    {
        if (! in_array($loan->status, [LoanStatus::Active, LoanStatus::Paid], true)) {
            throw new BusinessRuleException('Only active or paid loans can be reconciled from payment history.');
        }

        $payments = $loan->payments()->reorder();
        if ($asOf !== null) {
            $payments->whereDate('payment_date', '<=', $asOf);
        }
        $paid = (string) $payments->sum('amount');
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
            'end_date' => $paidOff ? ($asOf ?? now()->toDateString()) : null,
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
        return LoanRate::normalize((string) $this->requiredSetting("loans.{$type->value}.annual_interest_rate"));
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
