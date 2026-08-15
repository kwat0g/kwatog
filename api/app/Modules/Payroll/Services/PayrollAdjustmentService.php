<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PayrollAdjustmentService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $query = PayrollAdjustment::query()
            ->with(['period', 'employee.department', 'employee.position', 'originalPayroll', 'approver']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['employee_id'])) {
            $emp = Employee::tryDecodeHash((string) $filters['employee_id']);
            if ($emp) $query->where('employee_id', $emp);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $dir  = $filters['direction'] ?? 'desc';
        $allowed = ['created_at', 'amount', 'status'];
        if (in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $dir);
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);
        return $query->paginate($perPage);
    }

    public function create(array $data, User $user): PayrollAdjustment
    {
        return DB::transaction(function () use ($data, $user) {
            /** @var Payroll $original */
            $original = Payroll::findOrFail($data['original_payroll_id']);
            $period   = $original->period;

            // Adjustments are only meaningful against finalized periods (you can
            // edit drafts directly). Reject otherwise.
            if ($period->status !== PayrollPeriodStatus::Finalized) {
                throw new BusinessRuleException('Adjustments can only be raised against finalized payroll periods.');
            }

            $adj = PayrollAdjustment::create([
                'payroll_period_id'   => $period->id,
                'employee_id'         => $original->employee_id,
                'original_payroll_id' => $original->id,
                'type'                => $data['type'],
                'amount'              => $data['amount'],
                'reason'              => $data['reason'],
                'created_by'          => $user->id,
            ]);
            // status non-fillable; service-only.
            $adj->forceFill(['status' => PayrollAdjustmentStatus::Pending->value])->save();
            return $adj;
        });
    }

    public function approve(PayrollAdjustment $adjustment, User $user): PayrollAdjustment
    {
        if ($adjustment->status !== PayrollAdjustmentStatus::Pending) {
            throw new BusinessRuleException('Only pending adjustments can be approved.');
        }
        return DB::transaction(function () use ($adjustment, $user) {
            // Lock-then-guard: re-read so a stale approve/reject cannot race
            // the decision and flip the status after the other one committed.
            $locked = PayrollAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());
            if ($locked->status !== PayrollAdjustmentStatus::Pending) {
                throw new BusinessRuleException('Only pending adjustments can be approved.');
            }
            if ((int) $locked->created_by === (int) $user->id) {
                throw new BusinessRuleException('You cannot approve your own payroll adjustment.');
            }
            $locked->status      = PayrollAdjustmentStatus::Approved;
            $locked->approved_by = $user->id;
            $locked->save();
            return $locked->fresh();
        });
    }

    public function reject(PayrollAdjustment $adjustment, User $user, ?string $remarks = null): PayrollAdjustment
    {
        if ($adjustment->status !== PayrollAdjustmentStatus::Pending) {
            throw new BusinessRuleException('Only pending adjustments can be rejected.');
        }
        return DB::transaction(function () use ($adjustment, $user, $remarks) {
            $locked = PayrollAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());
            if ($locked->status !== PayrollAdjustmentStatus::Pending) {
                throw new BusinessRuleException('Only pending adjustments can be rejected.');
            }
            $locked->status      = PayrollAdjustmentStatus::Rejected;
            $locked->approved_by = $user->id;
            if ($remarks) {
                $locked->reason = trim($locked->reason."\n\n[Rejected: {$remarks}]");
            }
            $locked->save();
            return $locked->fresh();
        });
    }
}
