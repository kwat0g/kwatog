<?php

declare(strict_types=1);

namespace App\Modules\Loans\Services;

use App\Common\Services\ApprovalService;
use App\Common\Services\DocumentSequenceService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanPaymentType;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LoanService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly AmortizationService $amortization,
        private readonly ApprovalService $approvals,
    ) {}

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $q = EmployeeLoan::query()->with('employee:id,employee_no,first_name,middle_name,last_name,suffix,department_id');
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

        // Row-level filtering. Admin and Finance/approvers see everything.
        // Department Head sees own + their dept. Everyone else sees only their own.
        if ($user) {
            $roleSlug = $user->role?->slug;
            $isAdmin = $roleSlug === 'system_admin';
            $isFinance = $user->hasPermission('loans.approve');
            if (! $isAdmin && ! $isFinance) {
                $employeeId = $user->employee_id;
                if ($user->hasPermission('attendance.ot.approve') /* loose proxy for dept_head */
                    || $roleSlug === 'department_head') {
                    $deptId = \App\Modules\HR\Models\Employee::query()->whereKey($employeeId)->value('department_id');
                    $q->where(function ($qq) use ($employeeId, $deptId) {
                        $qq->where('employee_id', $employeeId);
                        if ($deptId) $qq->orWhereHas('employee', fn ($e) => $e->where('department_id', $deptId));
                    });
                } else {
                    $q->where('employee_id', $employeeId);
                }
            }
        }

        return $q->orderByDesc('created_at')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(EmployeeLoan $loan): EmployeeLoan
    {
        return $loan->load(['employee', 'payments']);
    }

    /** @return array{principal_max:string, has_active:bool} */
    public function limitsFor(Employee $employee, LoanType $type): array
    {
        $multiplier = (float) (app(\App\Common\Services\SettingsService::class)
            ->get('loans.cash_advance.max_multiplier', 1.0));
        $base = $employee->basic_monthly_salary
            ? (float) $employee->basic_monthly_salary
            : (float) ($employee->daily_rate ?? 0) * 22;
        $max = $type === LoanType::CashAdvance ? $base * $multiplier : $base; // company loan max = 1 month basic
        $hasActive = EmployeeLoan::query()
            ->where('employee_id', $employee->id)
            ->where('loan_type', $type->value)
            ->whereIn('status', [LoanStatus::Pending->value, LoanStatus::Active->value])
            ->exists();

        return ['principal_max' => number_format($max, 2, '.', ''), 'has_active' => $hasActive];
    }

    public function request(int $employeeId, LoanType $type, array $data): EmployeeLoan
    {
        return DB::transaction(function () use ($employeeId, $type, $data) {
            $employee = Employee::findOrFail($employeeId);

            // One active loan per type rule.
            $hasActive = EmployeeLoan::query()
                ->where('employee_id', $employeeId)
                ->where('loan_type', $type->value)
                ->whereIn('status', [LoanStatus::Pending->value, LoanStatus::Active->value])
                ->exists();
            if ($hasActive) {
                throw new RuntimeException("An active or pending {$type->value} already exists for this employee.");
            }

            // Cap check.
            $limits = $this->limitsFor($employee, $type);
            if (bccomp((string) $data['principal'], $limits['principal_max'], 2) > 0) {
                throw new RuntimeException("Principal exceeds maximum of ₱{$limits['principal_max']} for {$type->value}.");
            }

            $sequenceKey = $type === LoanType::CashAdvance ? 'cash_advance' : 'loan';
            $loanNo = $this->sequences->generate($sequenceKey);

            $periods = (int) $data['pay_periods'];
            $perPeriod = $this->amortization->monthlyAmortization((string) $data['principal'], $periods);
            $chainSize = $type === LoanType::CompanyLoan ? 4 : 3;

            $loan = EmployeeLoan::create([
                'loan_no'                => $loanNo,
                'employee_id'            => $employeeId,
                'loan_type'              => $type->value,
                'principal'              => $data['principal'],
                'interest_rate'          => 0.00,
                'monthly_amortization'   => $perPeriod,
                'total_paid'             => 0,
                'balance'                => $data['principal'],
                'pay_periods_total'      => $periods,
                'pay_periods_remaining'  => $periods,
                'approval_chain_size'    => $chainSize,
                'purpose'                => $data['purpose'] ?? null,
                'status'                 => LoanStatus::Pending->value,
            ]);

            $this->approvals->submit($loan, $type->workflowType(), (float) $data['principal']);

            return $loan->load('employee');
        });
    }

    public function approve(EmployeeLoan $loan, User $user, ?string $remarks = null): EmployeeLoan
    {
        return DB::transaction(function () use ($loan, $user, $remarks) {
            if ($loan->status !== LoanStatus::Pending) {
                throw new RuntimeException('Only pending loans can be approved.');
            }
            $this->approvals->approve($loan, $user, $remarks);

            if ($this->approvals->isFullyApproved($loan)) {
                $loan->update([
                    'status'     => LoanStatus::Active->value,
                    'start_date' => now()->toDateString(),
                ]);
            }

            return $loan->fresh(['employee', 'payments']);
        });
    }

    public function reject(EmployeeLoan $loan, User $user, string $reason): EmployeeLoan
    {
        return DB::transaction(function () use ($loan, $user, $reason) {
            if ($loan->status !== LoanStatus::Pending) {
                throw new RuntimeException('Only pending loans can be rejected.');
            }
            $this->approvals->reject($loan, $user, $reason);
            $loan->update(['status' => LoanStatus::Rejected->value]);
            return $loan->fresh(['employee']);
        });
    }

    public function cancel(EmployeeLoan $loan): EmployeeLoan
    {
        return DB::transaction(function () use ($loan) {
            if (! in_array($loan->status, [LoanStatus::Pending, LoanStatus::Active], true)) {
                throw new RuntimeException('Cannot cancel a finalized loan.');
            }
            $loan->update(['status' => LoanStatus::Cancelled->value]);
            return $loan->fresh(['employee', 'payments']);
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
            if ($loan->status !== LoanStatus::Active) {
                throw new RuntimeException('Only active loans accept payments.');
            }
            /** @var LoanPayment $payment */
            $payment = $loan->payments()->create([
                'payroll_id'   => $payrollId,
                'amount'       => $amount,
                'payment_date' => now()->toDateString(),
                'payment_type' => $type->value,
                'remarks'      => $remarks,
                'created_at'   => now(),
            ]);

            $newPaid    = bcadd((string) $loan->total_paid, $amount, 2);
            $newBalance = bcsub((string) $loan->principal, $newPaid, 2);
            $loan->update([
                'total_paid'             => $newPaid,
                'balance'                => $newBalance,
                'pay_periods_remaining'  => max(0, ((int) $loan->pay_periods_remaining) - 1),
                'status'                 => bccomp($newBalance, '0', 2) <= 0
                    ? LoanStatus::Paid->value
                    : LoanStatus::Active->value,
                'end_date'               => bccomp($newBalance, '0', 2) <= 0 ? now()->toDateString() : null,
            ]);

            return $payment;
        });
    }

    /** Used by Sprint 3's PayrollCalculatorService. */
    public function activeForPayroll(int $employeeId): \Illuminate\Database\Eloquent\Collection
    {
        return EmployeeLoan::query()
            ->where('employee_id', $employeeId)
            ->where('status', LoanStatus::Active->value)
            ->get();
    }
}
