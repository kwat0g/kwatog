<?php

declare(strict_types=1);

namespace App\Common\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Policies\LoanAccessPolicy;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Support\ReturnManagementAccess;
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
 *   - po    : PurchaseOrderAccessPolicy::visibleTo, unchanged;
 *   - payroll: no row scope exists in the module — holders of the read
 *             permission see every period, so the board's permission gate is
 *             already equivalent and hasScope() reports false;
 *   - asset : no row scope exists in the module — AssetService::list()
 *             shows every row to every assets.view holder, so the board's
 *             permission gate is already equivalent and hasScope() reports
 *             false (same shape as payroll);
 *   - bill_payment: the bills endpoint has no row scope, so holders of
 *             accounting.bills.view see every bill in the module;
 *   - return_request: customer service has a type scope; other roles use the
 *             module permission gate without an additional row scope. It is
 *             still excluded from class-wide DSS aggregates because one role
 *             is scoped.
 *
 * Before this existed the board gated cards on the module READ permissions
 * alone — and `leave.view` is a self-scoped permission granted to every role,
 * so every employee saw every other employee's pending leave cards, dates,
 * requester and approver remarks. A permission answers "may this role use the
 * module"; only the module's row scope answers "which rows may this user see".
 */
final class ApprovalSourceScope
{
    /** True when at least one viewer role has a source-specific row restriction. */
    public static function hasScope(string $class): bool
    {
        return in_array($class, [
            LeaveRequest::class,
            EmployeeLoan::class,
            PurchaseRequest::class,
            PurchaseOrder::class,
            ReturnRequest::class,
        ], true);
    }

    public static function hasScopeFor(string $class, User $user): bool
    {
        if ($class === ReturnRequest::class) {
            return ReturnManagementAccess::customerReturnsOnly($user);
        }

        return self::hasScope($class);
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

        if (! self::hasScopeFor($class, $user)) {
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
            PurchaseOrder::class => app(PurchaseOrderAccessPolicy::class)->visibleTo($query, $user),
            ReturnRequest::class => $query->when(
                ReturnManagementAccess::customerReturnsOnly($user),
                fn (Builder $query) => $query->where('type', ReturnRequestType::CustomerReturn->value),
            ),
            default => $query,
        };
    }
}
