<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalDelegation;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Auth\Access\AuthorizationException;
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
            $this->assertCanAccessPayroll($original, $user);
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

            $audience = ApprovalDelegation::approvalAudience('payroll.adjustments.approve')
                ->reject(fn (User $approver): bool => (int) $approver->id === (int) $user->id);
            app(NotificationService::class)->send($audience, 'approval.action_required', [
                'title' => 'Payroll adjustment needs approval',
                'message' => 'A payroll adjustment is waiting for review.',
                'link_to' => '/payroll/adjustments',
                'entity_type' => 'payroll_adjustment',
                'entity_id' => $adj->hash_id,
            ], "payroll-adjustment:approval-required:{$adj->id}");

            return $adj;
        });
    }

    /**
     * The submitted payroll hash is user-controlled input. Requiring the
     * adjustment permission alone is not enough: a crafted hash must not let
     * a requester mutate another employee's finalized payroll.
     */
    private function assertCanAccessPayroll(Payroll $payroll, User $user): void
    {
        if ($user->role?->slug === 'system_admin' || $user->hasPermission('payroll.payslip.view_all')) {
            return;
        }

        if ($user->employee_id && (int) $user->employee_id === (int) $payroll->employee_id) {
            return;
        }

        if ($user->role?->slug === 'department_head' && $user->employee_id) {
            $requesterDepartment = Employee::query()->whereKey($user->employee_id)->value('department_id');
            $payrollDepartment = Employee::query()->whereKey($payroll->employee_id)->value('department_id');
            if ($requesterDepartment && (int) $requesterDepartment === (int) $payrollDepartment) {
                return;
            }
        }

        throw new AuthorizationException('You do not have permission to create an adjustment for this payroll.');
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
            if (! ApprovalDelegation::canActForPermission($user, 'payroll.adjustments.approve', [(int) $locked->created_by])) {
                throw new AuthorizationException('You do not have permission to approve payroll adjustments.');
            }
            $locked->status      = PayrollAdjustmentStatus::Approved;
            $locked->approved_by = $user->id;
            $locked->save();
            $this->notifyRequester($locked, 'approval.completed', 'Payroll adjustment approved');
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
            if ((int) $locked->created_by === (int) $user->id) {
                throw new BusinessRuleException('You cannot reject your own payroll adjustment.');
            }
            if (! ApprovalDelegation::canActForPermission($user, 'payroll.adjustments.reject', [(int) $locked->created_by])) {
                throw new AuthorizationException('You do not have permission to reject payroll adjustments.');
            }
            $locked->status      = PayrollAdjustmentStatus::Rejected;
            $locked->approved_by = $user->id;
            if ($remarks) {
                $locked->reason = trim($locked->reason."\n\n[Rejected: {$remarks}]");
            }
            $locked->save();
            $this->notifyRequester($locked, 'approval.rejected', 'Payroll adjustment rejected');
            return $locked->fresh();
        });
    }

    private function notifyRequester(PayrollAdjustment $adjustment, string $type, string $title): void
    {
        $requester = User::query()->whereKey($adjustment->created_by)->where('is_active', true)->first();
        if (! $requester) {
            return;
        }

        app(NotificationService::class)->send($requester, $type, [
            'title' => $title,
            'message' => $type === 'approval.rejected' ? 'Your payroll adjustment was rejected.' : 'Your payroll adjustment was approved.',
            'link_to' => '/payroll/adjustments',
            'entity_type' => 'payroll_adjustment',
            'entity_id' => $adjustment->hash_id,
        ], "payroll-adjustment:decision:{$adjustment->id}:{$type}");
    }
}
