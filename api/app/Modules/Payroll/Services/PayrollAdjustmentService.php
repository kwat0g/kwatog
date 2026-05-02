<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
                throw new RuntimeException('Adjustments can only be raised against finalized payroll periods.');
            }

            return PayrollAdjustment::create([
                'payroll_period_id'   => $period->id,
                'employee_id'         => $original->employee_id,
                'original_payroll_id' => $original->id,
                'type'                => $data['type'],
                'amount'              => $data['amount'],
                'reason'              => $data['reason'],
                'status'              => PayrollAdjustmentStatus::Pending->value,
            ]);
        });
    }

    public function approve(PayrollAdjustment $adjustment, User $user): PayrollAdjustment
    {
        if ($adjustment->status !== PayrollAdjustmentStatus::Pending) {
            throw new RuntimeException('Only pending adjustments can be approved.');
        }
        $adjustment->status      = PayrollAdjustmentStatus::Approved;
        $adjustment->approved_by = $user->id;
        $adjustment->save();
        return $adjustment->fresh();
    }

    public function reject(PayrollAdjustment $adjustment, User $user, ?string $remarks = null): PayrollAdjustment
    {
        if ($adjustment->status !== PayrollAdjustmentStatus::Pending) {
            throw new RuntimeException('Only pending adjustments can be rejected.');
        }
        $adjustment->status      = PayrollAdjustmentStatus::Rejected;
        $adjustment->approved_by = $user->id;
        if ($remarks) {
            $adjustment->reason = trim($adjustment->reason."\n\n[Rejected: {$remarks}]");
        }
        $adjustment->save();
        return $adjustment->fresh();
    }
}
