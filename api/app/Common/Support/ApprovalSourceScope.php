<?php

declare(strict_types=1);

namespace App\Common\Support;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Policies\LoanAccessPolicy;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Row-level visibility for approval-board cards.
 *
 * The board must never surface a record the owning module would refuse in its
 * own list endpoint. Each approvable therefore reuses its module's row scope
 * verbatim instead of re-deriving one from view permissions:
 *
 *   - leave : the DepartmentScope ladder LeaveRequestService applies
 *             (approve_hr = all, approve_dept = department + own, else own);
 *   - loan  : LoanAccessPolicy::visibleTo, unchanged;
 *   - pr    : PurchaseRequestAccessPolicy::visibleTo, unchanged;
 *   - po    : the mirror of PurchaseOrderService::list's inline scope
 *             (po.approve = all; department head = own + own department via
 *             the linked PR; else own creations) — kept here as a mirror
 *             until that scope graduates into a policy of its own;
 *   - payroll: no row scope exists in the module — holders of the read
 *             permission see every period, so the board's permission gate is
 *             already equivalent and hasScope() reports false.
 *
 * Before this existed the board gated cards on the module READ permissions
 * alone — and `leave.view` is a self-scoped permission granted to every role,
 * so every employee saw every other employee's pending leave cards, dates,
 * requester and approver remarks. A permission answers "may this role use the
 * module"; only the module's row scope answers "which rows may this user see".
 */
final class ApprovalSourceScope
{
    public static function hasScope(string $class): bool
    {
        return in_array($class, [
            LeaveRequest::class,
            EmployeeLoan::class,
            PurchaseRequest::class,
            PurchaseOrder::class,
        ], true);
    }

    /**
     * Ids from $ids that $user may actually see in the owning module.
     *
     * @param  array<int, int>  $ids
     * @return array<int, bool> ids flipped for O(1) membership checks
     */
    public static function visibleIds(string $class, array $ids, User $user): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        if (! self::hasScope($class)) {
            // Unscoped kinds stay permission-gated at the caller; reaching
            // here means the gate passed, so every row is visible.
            return array_fill_keys($ids, true);
        }

        $query = $class::query()->whereIn('id', $ids);
        $query = self::apply($class, $query, $user);

        return array_fill_keys(array_map('intval', $query->pluck('id')->all()), true);
    }

    private static function apply(string $class, Builder $query, User $user): Builder
    {
        return match ($class) {
            LeaveRequest::class => DepartmentScope::apply(
                $query,
                $user,
                viewAllPermission: 'leave.approve_hr',
                departmentPermission: 'leave.approve_dept',
                deptColumn: 'department_id',
                selfColumn: 'employee_id',
                selfId: $user->employee_id ? (int) $user->employee_id : null,
                deptRelation: 'employee',
            ),
            EmployeeLoan::class => app(LoanAccessPolicy::class)->visibleTo($query, $user),
            PurchaseRequest::class => app(PurchaseRequestAccessPolicy::class)->visibleTo($query, $user),
            PurchaseOrder::class => self::purchaseOrderScope($query, $user),
            default => $query,
        };
    }

    /** @param Builder<PurchaseOrder> $query */
    private static function purchaseOrderScope(Builder $query, User $user): Builder
    {
        // hasPermission() short-circuits for system_admin, so the admin tier
        // needs no role-name branch here.
        if ($user->hasPermission('purchasing.po.approve')) {
            return $query;
        }

        // Mirror of PurchaseOrderService::list's row filter, including its
        // departmental tier for department heads (which is role-keyed there).
        return $query->where(function (Builder $q) use ($user): void {
            $q->where('created_by', $user->id);

            if ($user->role?->slug === 'department_head') {
                $departmentId = $user->employee_id
                    ? Employee::query()->whereKey($user->employee_id)->value('department_id')
                    : null;
                if ($departmentId !== null) {
                    $q->orWhereHas('purchaseRequest', fn (Builder $pr) => $pr->where('department_id', $departmentId));
                }
            }
        });
    }
}
