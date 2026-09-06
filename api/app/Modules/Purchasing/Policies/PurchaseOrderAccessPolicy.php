<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Policies;

use App\Common\Models\ApprovalDelegation;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
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
 *   - approval-chain step roles            → own creations + POs whose current
 *                                            approval records carry their step
 *                                            role (the orders waiting on them);
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

        $stepRoles = $this->approvalRoleSlugs($user);

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

            // PS-01 — an approver on a plant-wide chain step has to be able to
            // find the orders waiting on them. approvalRecords is already
            // constrained to is_current and only exists once a PO is submitted,
            // so this never exposes a draft. Same shape as
            // PurchaseRequestAccessPolicy::visibleTo.
            if ($stepRoles !== []) {
                $scope->orWhereHas('approvalRecords', function ($records) use ($stepRoles): void {
                    $records->whereIn('role_slug', $stepRoles);
                });
            }
        });
    }

    /**
     * Approval roles held directly or through an active delegation.
     *
     * Every purchase_order step is a plant-wide office (purchasing_officer →
     * finance_officer → system_admin), so unlike PurchaseRequestAccessPolicy
     * there is no departmental step role to subtract.
     *
     * @return list<string>
     */
    private function approvalRoleSlugs(User $user): array
    {
        $roles = [];
        if ($user->role?->slug !== null) {
            $roles[] = $user->role->slug;
        }

        return array_values(array_unique([
            ...$roles,
            ...ApprovalDelegation::actsForRoles($user->id, now()),
        ]));
    }
}
