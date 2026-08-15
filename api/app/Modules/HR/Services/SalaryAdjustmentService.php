<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\ApprovalService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmploymentChangeType;
use App\Modules\HR\Enums\SalaryAdjustmentStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSalaryHistory;
use App\Modules\HR\Models\EmploymentHistory;
use App\Modules\HR\Models\SalaryAdjustment;
use Illuminate\Support\Facades\DB;

/**
 * REC-03 — maker-checker gate for salary changes.
 *
 * request()  captures the current pay, records a PENDING SalaryAdjustment, and
 *            submits it to the `salary_adjustment` approval chain. Nothing on the
 *            employee row changes yet.
 * approve()  advances one approval step; when the chain is fully approved the new
 *            pay is applied (employee row + effective-dated salary-history row +
 *            employment-history event).
 * reject()   discards the request; pay is never touched.
 *
 * The requester cannot approve their own adjustment — ApprovalService reads
 * SalaryAdjustment::approvalSubmitterId() and blocks self-action.
 */
class SalaryAdjustmentService
{
    private const WORKFLOW_TYPE = 'salary_adjustment';

    public function __construct(private readonly ApprovalService $approvals) {}

    /**
     * @param  array{to_basic_monthly_salary?: string|float|null, to_semi_monthly_rate?: string|float|null, effective_date: string, reason?: string|null}  $data
     */
    public function request(Employee $employee, array $data, User $requester): SalaryAdjustment
    {
        return DB::transaction(function () use ($employee, $data, $requester): SalaryAdjustment {
            $adjustment = SalaryAdjustment::create([
                'employee_id'               => $employee->id,
                'from_basic_monthly_salary' => $employee->basic_monthly_salary,
                'from_semi_monthly_rate'           => $employee->semi_monthly_rate,
                'to_basic_monthly_salary'   => $data['to_basic_monthly_salary'] ?? null,
                'to_semi_monthly_rate'             => $data['to_semi_monthly_rate'] ?? null,
                'effective_date'            => $data['effective_date'],
                'reason'                    => $data['reason'] ?? null,
                'requested_by'              => $requester->id,
            ]);

            $amount = $data['to_basic_monthly_salary'] ?? $data['to_semi_monthly_rate'] ?? null;
            $this->approvals->submit($adjustment, self::WORKFLOW_TYPE, $amount !== null ? (float) $amount : null);

            // status is excluded from $fillable (mass-assignment hardening); the DB
            // default 'pending' applies on insert, so reload to surface it in-memory.
            return $adjustment->fresh();
        });
    }

    public function approve(SalaryAdjustment $adjustment, User $user, ?string $remarks = null): SalaryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user, $remarks): SalaryAdjustment {
            // Lock the adjustment row so the "apply once" guard in apply()
            // serializes concurrent approvers of the final workflow step — two
            // in-flight approvers of a multi-step chain would otherwise both
            // pass the stale applied_at check and double-apply the raise.
            $locked = SalaryAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());

            $this->approvals->approve($locked, $user, $remarks);

            if ($this->approvals->isFullyApproved($locked)) {
                $this->apply($locked);
            }

            return $locked->fresh(['employee', 'requester']);
        });
    }

    public function reject(SalaryAdjustment $adjustment, User $user, string $remarks): SalaryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $user, $remarks): SalaryAdjustment {
            $locked = SalaryAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());

            $this->approvals->reject($locked, $user, $remarks);
            $locked->forceFill(['status' => SalaryAdjustmentStatus::Rejected])->save();

            return $locked->fresh(['employee', 'requester']);
        });
    }

    /**
     * Apply the approved pay to the employee, effective-date it in salary history,
     * and log the change. Idempotent: a second call after applied_at is a no-op.
     */
    private function apply(SalaryAdjustment $adjustment): void
    {
        if ($adjustment->applied_at !== null) {
            return;
        }

        $employee = $adjustment->employee;
        $changes = [];
        if ($adjustment->to_basic_monthly_salary !== null) {
            $changes['basic_monthly_salary'] = $adjustment->to_basic_monthly_salary;
        }
        if ($adjustment->to_semi_monthly_rate !== null) {
            $changes['semi_monthly_rate'] = $adjustment->to_semi_monthly_rate;
        }
        if (! empty($changes)) {
            $employee->update($changes);
        }

        // basic_monthly_salary is NOT NULL on this table and is what the payroll
        // calculator prorates against, so a semi-monthly adjustment must store
        // the MONTHLY EQUIVALENT (rate x 2) alongside the per-cutoff rate. Left
        // null, the insert fails outright; left at the employee's own (null)
        // monthly salary, proration across the raise would read zero.
        $historyMonthly = $adjustment->to_basic_monthly_salary
            ?? ($adjustment->to_semi_monthly_rate !== null
                ? bcmul((string) $adjustment->to_semi_monthly_rate, '2', 2)
                : $employee->monthlyEquivalentSalary());

        EmployeeSalaryHistory::create([
            'employee_id'          => $employee->id,
            'basic_monthly_salary' => $historyMonthly,
            'semi_monthly_rate'    => $adjustment->to_semi_monthly_rate,
            'effective_date'       => $adjustment->effective_date,
            'created_by'           => $adjustment->requested_by,
        ]);

        EmploymentHistory::create([
            'employee_id'    => $employee->id,
            'change_type'    => EmploymentChangeType::SalaryAdjusted->value,
            'from_value'     => [
                'basic_monthly_salary' => $adjustment->from_basic_monthly_salary,
                'semi_monthly_rate'           => $adjustment->from_semi_monthly_rate,
            ],
            'to_value'       => [
                'basic_monthly_salary' => $adjustment->to_basic_monthly_salary,
                'semi_monthly_rate'           => $adjustment->to_semi_monthly_rate,
            ],
            'effective_date' => $adjustment->effective_date->toDateString(),
            'approved_by'    => $adjustment->requested_by,
            'created_at'     => now(),
        ]);

        $adjustment->forceFill([
            'status'     => SalaryAdjustmentStatus::Approved,
            'applied_at' => now(),
        ])->save();
    }
}
