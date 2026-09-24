<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Policies;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\ApprovalService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

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
     * Action ownership, mirroring PurchaseRequestAccessPolicy: permissions
     * decide whether a route is available, these decide which PO rows that
     * permission may mutate. Visibility is not enough — a department head can
     * see their department's POs without owning them.
     */
    public function canManageDraft(User $user, PurchaseOrder $po): bool
    {
        // Draft management is the buyer's action: every write route
        // (create/update/delete/submit/close) is gated by
        // `purchasing.po.create`. `isOwner()` alone also returns true for the
        // `purchasing.po.approve` tier (it is the module's global row tier), so
        // without this check the action map advertised Update/Delete/Submit to
        // Finance and the VP — buttons the route then refused with a 403.
        return $user->hasPermission('purchasing.po.create')
            && $po->status === PurchaseOrderStatus::Draft
            && $this->isOwner($user, $po);
    }

    public function canCancel(User $user, PurchaseOrder $po): bool
    {
        return $user->hasPermission('purchasing.po.create')
            && in_array($po->status, [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::PendingApproval,
                PurchaseOrderStatus::Approved,
                PurchaseOrderStatus::Sent,
                PurchaseOrderStatus::Acknowledged,
                PurchaseOrderStatus::SupplierProposed,
                PurchaseOrderStatus::SupplierDeclined,
                PurchaseOrderStatus::PartiallyReceived,
            ], true) && $this->isOwner($user, $po);
    }

    public function canSend(User $user, PurchaseOrder $po): bool
    {
        return $user->hasPermission('purchasing.po.send')
            && $po->status === PurchaseOrderStatus::Approved
            && $this->isOwner($user, $po);
    }

    public function canClose(User $user, PurchaseOrder $po): bool
    {
        return $user->hasPermission('purchasing.po.create')
            && $po->status === PurchaseOrderStatus::Received
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

    /**
     * Approval roles held directly or through an active delegation.
     *
     * Every purchase_order step is a plant-wide office (finance_officer →
     * vice_president), so unlike PurchaseRequestAccessPolicy
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

    public function canView(User $user, PurchaseOrder $po): bool
    {
        return $this->visibleTo(PurchaseOrder::query(), $user)
            ->whereKey($po->id)
            ->exists();
    }

    /**
     * PU-13 — action decisions for detail/action responses, mirroring
     * PurchaseRequestAccessPolicy::actionsFor. The SPA renders buttons from
     * this map instead of guessing from status + permission, so a hidden
     * button and a refused request can never disagree. Approve/reject mirror
     * ApprovalService's own guards: current step role via nextStep(),
     * self-approval via created_by, and vendor SoD via vendor.created_by;
     * the service re-checks under lock — this is UX truth, not the security
     * boundary.
     *
     * @return array<string, bool>
     */
    public function actionsFor(User $user, PurchaseOrder $po): array
    {
        $canView = $this->canView($user, $po);
        $canManageDraft = $this->canManageDraft($user, $po);
        $reconfirmationRequired = $po->relationLoaded('rfqQuoteReconfirmation')
            ? $po->rfqQuoteReconfirmation?->status === 'pending'
            : $po->rfqQuoteReconfirmation()->where('status', 'pending')->exists();

        $isPendingApproval = $po->status === PurchaseOrderStatus::PendingApproval;
        $selfSubmitted = (int) $po->created_by === (int) $user->id;
        $stepRoles = $this->approvalRoleSlugs($user);

        // The approve/reject routes are gated by `purchasing.po.approve`.
        // Matching the step role alone would advertise the button to a
        // delegate who holds the role but not the route permission.
        // The current step is obtained via ApprovalService::nextStep(), not
        // an any-pending query, so we never expose a button for a later step.
        $canActOnCurrentStep = false;
        if ($user->hasPermission('purchasing.po.approve')
            && $isPendingApproval
            && ! $selfSubmitted
            && $stepRoles !== []) {
            $nextStep = app(ApprovalService::class)->nextStep($po);
            if ($nextStep !== null && in_array($nextStep->role_slug, $stepRoles, true)) {
                $canActOnCurrentStep = true;
            }
        }

        // Approve mirrors PurchaseOrderService::approve() guards, which include
        // vendor SoD check. Reject mirrors reject() which does NOT include SoD.
        $vendorSodBlocked = $canActOnCurrentStep && $this->vendorSodBlocks($po, $user);
        $canApprove = $canActOnCurrentStep && ! $vendorSodBlocked;
        $canReject = $canActOnCurrentStep;

        return [
            'can_view' => $canView,
            'can_update' => $canManageDraft,
            'can_delete' => $canManageDraft,
            'can_submit' => $canManageDraft && ! $reconfirmationRequired,
            'can_approve' => $canApprove,
            'can_reject' => $canReject,
            'can_send' => $this->canSend($user, $po),
            'can_cancel' => $this->canCancel($user, $po),
            'can_close' => $this->canClose($user, $po),
            'can_acknowledge_budget' => $this->canAcknowledgeBudget($user, $po),
            'can_print' => $canView,
            'reconfirmation_required' => $reconfirmationRequired,
            // Lets the SPA say why Approve is missing instead of showing Reject alone.
            'approve_blocked_by_vendor_sod' => $vendorSodBlocked,
        ];
    }

    /**
     * OGAMI-002 — true when this user created the PO's vendor and holds no
     * override, so may not approve spend to it. The one implementation:
     * PurchaseOrderService::assertVendorSod() throws on it and the action
     * map hides Approve on it.
     */
    public function vendorSodBlocks(PurchaseOrder $po, User $user): bool
    {
        // Gracefully skip when the schema does not record who created a vendor.
        if (! Schema::hasColumn('vendors', 'created_by')) {
            return false;
        }

        $vendorCreatorId = Vendor::query()
            ->whereKey($po->vendor_id)
            ->value('created_by');

        if ($vendorCreatorId === null) {
            return false; // unknown maker — guard cannot fire.
        }
        if ((int) $vendorCreatorId !== (int) $user->id) {
            return false; // different user — allowed.
        }
        if ($user->hasPermission(PurchaseOrderService::VENDOR_SOD_OVERRIDE_PERMISSION)) {
            return false; // explicit override.
        }

        return true; // SoD blocks this user.
    }
}
