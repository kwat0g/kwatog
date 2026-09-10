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
 *   - chain participants                   → POs waiting on a step that names
 *                                            one of the caller's roles;
 *   - everyone else                        → own creations only.
 *
 * The chain-participant branch (2026-09-10, PS-01b) mirrors the one
 * PurchaseRequestAccessPolicy already grew: a step-2 finance approver creates
 * no POs and holds no department, so without it the Approval Board hid every
 * card naming their step while the badge still counted them — the workflow
 * stalled invisibly (audit PS-01).
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

        $stepRoles = $this->chainStepRoles($user);

        return $query->where(function (Builder $scope) use ($user, $stepRoles): void {
            $scope->where('purchase_orders.created_by', $user->id);

            if ($user->role?->slug === 'department_head') {
                $departmentId = $user->employee_id !== null
                    ? Employee::query()->whereKey($user->employee_id)->value('department_id')
                    : null;
                if ($departmentId !== null) {
                    $scope->orWhereHas('purchaseRequest', fn (Builder $pr) => $pr->where('department_id', $departmentId));
                }
            }

            // An approver on a chain step has to be able to find the POs
            // waiting on them. approvalRecords is constrained to is_current
            // and only exists once the PO is submitted, so this never exposes
            // somebody else's draft.
            if ($stepRoles !== []) {
                $scope->orWhereHas('approvalRecords', function (Builder $records) use ($stepRoles): void {
                    $records->whereIn('role_slug', $stepRoles)
                        ->where('action', 'pending');
                });
            }
        });
    }

    public function canView(User $user, PurchaseOrder $po): bool
    {
        if ($user->hasPermission('purchasing.po.approve')) {
            return true;
        }

        if ((int) $po->created_by === (int) $user->id) {
            return true;
        }

        if ($user->role?->slug === 'department_head' && $user->employee_id !== null) {
            $departmentId = (int) Employee::query()->whereKey($user->employee_id)->value('department_id');
            if ($departmentId !== null
                && (int) $po->purchaseRequest()->value('department_id') === $departmentId) {
                return true;
            }
        }

        // You cannot approve what you cannot open: a chain approver reads the
        // rows its own pending step appears on.
        $stepRoles = $this->chainStepRoles($user);
        if ($stepRoles === []) {
            return false;
        }

        return $po->approvalRecords()
            ->whereIn('role_slug', $stepRoles)
            ->where('action', 'pending')
            ->exists();
    }

    /**
     * PU-02 — who may edit/delete/submit a draft, cancel a live PO, or close
     * a received one. The route permission answers "may act on POs at all";
     * this answers "on THIS one". Mirrors the PR policy's draft management:
     * the creator, or anyone in the approver tier (po.approve holders, who
     * are company-wide in the module).
     */
    public function canManage(User $user, PurchaseOrder $po): bool
    {
        if ($user->hasPermission('purchasing.po.approve')) {
            return true;
        }

        return (int) $po->created_by === (int) $user->id;
    }

    /**
     * PU-02 — send/close/cancel are lifecycle decisions on documents someone
     * else may have created; purchasing operators (po.send / po.create
     * holders) act company-wide, matching how the module list treats them.
     */
    public function canOperate(User $user, PurchaseOrder $po): bool
    {
        if ($po->status === PurchaseOrderStatus::Draft) {
            return $this->canManage($user, $po);
        }

        return $user->hasPermission('purchasing.po.approve')
            || $user->hasPermission('purchasing.po.send')
            || (int) $po->created_by === (int) $user->id;
    }

    /**
     * PU-13 — the SPA renders buttons from this map instead of guessing from
     * status + role, the same way PurchaseRequestResource already does. Row
     * detail/action responses call it; the paginated list does not (the
     * delegation/step queries would run once per row).
     *
     * Approve/reject mirror ApprovalService's own guards (step-role match via
     * chainStepRoles, self-approval via created_by) so a hidden button and a
     * refused request can never disagree. The service re-checks under lock —
     * this is UX truth, not the security boundary.
     *
     * @return array<string, bool>
     */
    public function actionsFor(User $user, PurchaseOrder $po): array
    {
        $canManage = $this->canManage($user, $po);
        $canOperate = $this->canOperate($user, $po);

        $isPendingApproval = $po->status === PurchaseOrderStatus::PendingApproval;
        $selfBlocked = (int) $po->created_by === (int) $user->id;
        $stepRoles = $this->chainStepRoles($user);
        $holdsCurrentStep = $isPendingApproval && ! $selfBlocked && $stepRoles !== []
            && $po->approvalRecords()
                ->whereIn('role_slug', $stepRoles)
                ->where('action', 'pending')
                ->exists();

        return [
            'can_view'               => $this->canView($user, $po),
            'can_update'             => $canManage && $po->status === PurchaseOrderStatus::Draft,
            'can_delete'             => $canManage && $po->status === PurchaseOrderStatus::Draft,
            'can_submit'             => $canManage && $po->status === PurchaseOrderStatus::Draft,
            'can_approve'            => $isPendingApproval && $holdsCurrentStep,
            'can_reject'             => $isPendingApproval && $holdsCurrentStep,
            'can_send'               => $canOperate && $po->status === PurchaseOrderStatus::Approved,
            'can_cancel'             => $canOperate
                && ! in_array($po->status, [PurchaseOrderStatus::Cancelled, PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed], true),
            'can_close'              => $canOperate && $po->status === PurchaseOrderStatus::Received,
            'can_print'              => $this->canView($user, $po),
        ];
    }

    /**
     * The caller's approval-chain roles: the role slug itself plus any active
     * delegation. Kept in step with ApprovalService::userMayActFor so the
     * board can never show a card the approve endpoint would refuse — or
     * hide one it would accept.
     *
     * @return list<string>
     */
    private function chainStepRoles(User $user): array
    {
        if ($user->role?->slug === null) {
            return [];
        }

        $roles = [$user->role->slug];

        foreach (\App\Common\Models\ApprovalDelegation::actsForRoles($user->id, now()) as $delegated) {
            $roles[] = $delegated;
        }

        return array_values(array_unique($roles));
    }
}
