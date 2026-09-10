<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-side ROW visibility for purchase orders — the single source of truth.
 *
 * Three surfaces used to carry three copies of this rule and had drifted:
 * PurchaseOrderService::list (inline), GlobalSearchService (re-expressed
 * through DepartmentScope keyed on purchasing.pr.approve — wrong key: it
 * UNDER-exposed purchasing_officer, who is company-wide in the module, and
 * OVER-exposed any pr.approve holder linked to a department, e.g.
 * production_manager, who is authorship-only in the module) and the
 * approval board. All three now call this.
 *
 * The ladder, verbatim from the module list:
 *   - system_admin / purchasing.po.approve → every PO;
 *   - department_head                      → own creations + POs whose linked
 *                                            PR belongs to their department;
 *   - everyone else                        → own creations only.
 *
 * Columns are table-qualified so this survives callers that join tables
 * carrying same-named columns (vendors.created_by — migration 0222).
 */
final class PurchaseOrderAccessPolicy
{
    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function visibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        // hasPermission() short-circuits for system_admin, so the admin tier
        // needs no role-name branch here.
        if ($user->hasPermission('purchasing.po.approve')) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($user): void {
            $scope->where('purchase_orders.created_by', $user->id);

            if ($user->role?->slug === 'department_head') {
                $departmentId = $user->employee_id !== null
                    ? Employee::query()->whereKey($user->employee_id)->value('department_id')
                    : null;
                if ($departmentId !== null) {
                    $scope->orWhereHas('purchaseRequest', fn (Builder $pr) => $pr->where('department_id', $departmentId));
                }
            }
        });
    }

    /**
     * Action ownership, mirroring PurchaseRequestAccessPolicy: permissions
     * decide whether a route is available, these decide which PO rows that
     * permission may mutate. Visibility is not enough — a department head can
     * see their department's POs without owning them.
     */
    public function canManageDraft(User $user, PurchaseOrder $po): bool
    {
        return $po->status === PurchaseOrderStatus::Draft
            && $this->isOwner($user, $po);
    }

    public function canCancel(User $user, PurchaseOrder $po): bool
    {
        return in_array($po->status, [
            PurchaseOrderStatus::Draft,
            PurchaseOrderStatus::PendingApproval,
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::PartiallyReceived,
        ], true) && $this->isOwner($user, $po);
    }

    public function canSend(User $user, PurchaseOrder $po): bool
    {
        return $po->status === PurchaseOrderStatus::Approved
            && $this->isOwner($user, $po);
    }

    public function canClose(User $user, PurchaseOrder $po): bool
    {
        return $po->status === PurchaseOrderStatus::Received
            && $this->isOwner($user, $po);
    }

    public function canAcknowledgeBudget(User $user, PurchaseOrder $po): bool
    {
        if (! $user->hasPermission('budgeting.approve')) {
            return false;
        }

        // Finance acknowledges the budget gate across departments but is not
        // granted general purchasing row visibility by that permission.
        return $this->rowVisible($user, $po)
            || in_array($user->role?->slug, ['system_admin', 'finance_officer'], true);
    }

    /**
     * The PO creator, or the company-wide tier. `purchasing.po.approve` is
     * this module's global tier — the same office `visibleTo` grants every PO
     * — and hasPermission() short-circuits for system_admin.
     */
    private function isOwner(User $user, PurchaseOrder $po): bool
    {
        return (int) $po->created_by === (int) $user->id
            || $user->hasPermission('purchasing.po.approve');
    }

    /**
     * Single-row mirror of visibleTo(), for callers that already hold the row.
     * Reuses visibleTo() rather than re-expressing the ladder, so the two can
     * never drift.
     */
    private function rowVisible(User $user, PurchaseOrder $po): bool
    {
        return $this->visibleTo(PurchaseOrder::query(), $user)
            ->whereKey($po->id)
            ->exists();
    }

    public function canView(User $user, PurchaseOrder $po): bool
    {
        return $this->visibleTo(PurchaseOrder::query(), $user)
            ->whereKey($po->id)
            ->exists();
    }
}
