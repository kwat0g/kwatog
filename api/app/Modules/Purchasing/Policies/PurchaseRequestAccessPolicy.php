<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Policies;

use App\Common\Models\ApprovalDelegation;
use App\Common\Models\ApprovalRecord;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestSourcingMethod;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Server-side row and action scope for purchase requests.
 *
 * Permissions decide whether a route is available. This policy decides which
 * PR rows that permission may touch; the two checks must remain separate.
 */
final class PurchaseRequestAccessPolicy
{
    /**
     * The only role whose PR row-visibility is department-scoped.
     *
     * The seeded purchase_request chain has been money-only since 2026-09-10
     * (finance_officer → vice_president ≥ ₱50k — see WorkflowSeeder): no
     * workflow step is department-scoped any more, so `respectsDepartmentScope`
     * never finds a departmental step to enforce. This constant is NOT dead,
     * though — it still drives the LIST/row branches below: a department head
     * (who may raise PRs for their own department via the create gate) sees
     * their department's requests in addition to their own; every other
     * non-global role sees only what they requested plus the rows waiting on
     * a plant-wide step they hold.
     */
    private const DEPARTMENTAL_STEP_ROLE = 'department_head';

    /**
     * @param  Builder<PurchaseRequest>  $query
     * @return Builder<PurchaseRequest>
     */
    public function visibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobal($user)) {
            return $query;
        }

        $departmentId = $this->departmentId($user);
        $stepRoles = $this->plantWideStepRoles($user);

        return $query->where(function (Builder $scope) use ($user, $departmentId, $stepRoles): void {
            $scope->where('requested_by', $user->id);

            if ($departmentId !== null && $user->role?->slug === self::DEPARTMENTAL_STEP_ROLE) {
                $scope->orWhere('department_id', $departmentId);
            }

            // An approver on a plant-wide step has to be able to find the
            // requests waiting on them. approvalRecords is already constrained
            // to is_current and only exists once a PR is submitted, so this
            // never exposes somebody else's draft.
            if ($stepRoles !== []) {
                $scope->orWhereHas('approvalRecords', function ($records) use ($stepRoles): void {
                    $records->whereIn('role_slug', $stepRoles);
                });
            }
        });
    }

    public function canView(User $user, PurchaseRequest $pr): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        if ((int) $pr->requested_by === (int) $user->id) {
            return true;
        }

        if ($user->role?->slug === self::DEPARTMENTAL_STEP_ROLE
            && $this->departmentId($user) !== null
            && (int) $pr->department_id === $this->departmentId($user)) {
            return true;
        }

        // You cannot approve what you cannot open: a plant-wide chain approver
        // reads the rows its own steps appear on.
        return $this->isChainParticipant($user, $pr);
    }

    public function canManageDraft(User $user, PurchaseRequest $pr): bool
    {
        return $pr->status === PurchaseRequestStatus::Draft
            && $this->canView($user, $pr)
            && ($this->isGlobal($user) || (int) $pr->requested_by === (int) $user->id);
    }

    public function canCancel(User $user, PurchaseRequest $pr): bool
    {
        return in_array($pr->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Pending], true)
            && $this->canView($user, $pr)
            && ($this->isGlobal($user) || (int) $pr->requested_by === (int) $user->id);
    }

    public function canAssignDepartment(User $user, PurchaseRequest $pr, ?int $departmentId): bool
    {
        if (! $this->canManageDraft($user, $pr)) {
            return false;
        }

        return $this->isGlobal($user)
            || ($departmentId !== null && $departmentId === $this->departmentId($user));
    }

    public function canApprove(User $user, PurchaseRequest $pr): bool
    {
        if (! $user->hasPermission('purchasing.pr.approve')
            || $pr->status !== PurchaseRequestStatus::Pending
            || (int) $pr->requested_by === (int) $user->id) {
            return false;
        }

        return $this->mayActOnCurrentStep($user, $pr);
    }

    public function canReject(User $user, PurchaseRequest $pr): bool
    {
        return $this->canApprove($user, $pr);
    }

    public function canAcknowledgeBudget(User $user, PurchaseRequest $pr): bool
    {
        if (! $user->hasPermission('budgeting.approve')) {
            return false;
        }

        // Finance acknowledges the budget gate across departments but is not
        // granted general purchasing row visibility by that permission.
        return $this->canView($user, $pr)
            || in_array($user->role?->slug, ['system_admin', 'finance_officer'], true);
    }

    public function canConvert(User $user, PurchaseRequest $pr): bool
    {
        return $user->hasPermission('purchasing.po.create')
            && $this->canView($user, $pr)
            && ($pr->sourcing_method === PurchaseRequestSourcingMethod::DirectPo || $pr->rfqHandedBack());
    }

    /**
     * An approved PR (status approved = something is still unordered) may go
     * to RFQ when the buyer chose RFQ, when Direct PO could not auto-convert
     * (no preferred supplier or price), or when a previous RFQ handed a
     * remainder back. One RFQ at a time.
     */
    public function canStartRfq(User $user, PurchaseRequest $pr): bool
    {
        return $user->hasPermission('purchasing.rfq.manage')
            && $this->canView($user, $pr)
            && $pr->status === PurchaseRequestStatus::Approved
            && ($pr->sourcing_method === PurchaseRequestSourcingMethod::Rfq
                || $pr->po_conversion_status === PurchaseRequestConversionStatus::ManualRequired)
            && ! $pr->hasActiveRfq();
    }

    public function canSetSourcingMethod(User $user, PurchaseRequest $pr, PurchaseRequestSourcingMethod $method): bool
    {
        if ($method === PurchaseRequestSourcingMethod::Rfq && ! $pr->is_auto_generated) {
            return false;
        }
        $hasMethodPermission = $method === PurchaseRequestSourcingMethod::Rfq
            ? $user->hasPermission('purchasing.rfq.manage')
            : $user->hasPermission('purchasing.po.create');
        if (! $hasMethodPermission || ! $this->canView($user, $pr)) {
            return false;
        }
        if ($pr->status === PurchaseRequestStatus::Draft) {
            return $this->canManageDraft($user, $pr);
        }

        return $pr->status === PurchaseRequestStatus::Approved
            && $pr->sourcing_method === null
            && $pr->po_conversion_status === PurchaseRequestConversionStatus::SourcingPending
            && ! $this->hasLivePurchaseOrders($pr)
            && ! $pr->hasActiveRfq();
    }

    /**
     * Approval roles held directly or through an active delegation.
     *
     * @return list<string>
     */
    public function approvalRoleSlugs(User $user): array
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

    /**
     * @return array<string, bool>
     */
    public function actionsFor(User $user, PurchaseRequest $pr): array
    {
        $canManageDraft = $this->canManageDraft($user, $pr);

        return [
            'can_view' => $this->canView($user, $pr),
            'can_update' => $canManageDraft,
            'can_delete' => $canManageDraft,
            'can_submit' => $canManageDraft,
            'can_cancel' => $this->canCancel($user, $pr),
            'can_approve' => $this->canApprove($user, $pr),
            'can_reject' => $this->canReject($user, $pr),
            'can_acknowledge_budget' => $this->canAcknowledgeBudget($user, $pr),
            // canConvert() is the permission gate the convert endpoint uses and
            // is deliberately replay-tolerant: re-posting an already-converted
            // PR returns its existing POs. The *button* must be stricter — once
            // any live PO exists (including rows created before the manual
            // create path marked its source PR converted) the affordance clears
            // so the operator cannot fire a second conversion.
            // An RFQ hand-back is the one case where a live PO (the award's)
            // must not hide the button: the remainder is converted from here.
            'can_convert' => $this->canConvert($user, $pr)
                && $pr->status === PurchaseRequestStatus::Approved
                && (! $this->hasLivePurchaseOrders($pr) || $pr->rfqHandedBack())
                && ! $pr->hasActiveRfq(),
            'can_start_rfq' => $this->canStartRfq($user, $pr),
            'can_set_sourcing_method' => $this->canSetSourcingMethod($user, $pr, PurchaseRequestSourcingMethod::DirectPo)
                || $this->canSetSourcingMethod($user, $pr, PurchaseRequestSourcingMethod::Rfq),
            'can_print' => $this->canView($user, $pr),
        ];
    }

    /**
     * Does this PR already have a PO that still counts as its conversion? A
     * cancelled or soft-deleted PO is a failed attempt — the reopen logic moves
     * the PR back to `approved`, so those do not count.
     */
    private function hasLivePurchaseOrders(PurchaseRequest $pr): bool
    {
        $orders = $pr->relationLoaded('purchaseOrders')
            ? $pr->purchaseOrders
            : $pr->purchaseOrders()->get();

        return $orders->contains(
            static fn ($po): bool => $po->status !== PurchaseOrderStatus::Cancelled
                && $po->deleted_at === null,
        );
    }

    /**
     * Departmental guard on the PR approve/reject action, kept from the era
     * when the chain's first step was a department head.
     *
     * The seeded chain is now money-only (finance_officer → vice_president),
     * so this never fires for it: no current step names `department_head`.
     * It remains because the method is public API — ApprovalRefusalRenderingTest
     * and any future chain that reintroduces a departmental step call it — and
     * because its refusal sentence ("You can only approve purchase requests
     * from your own department.") is the product behaviour PurchaseRequest-
     * Service::assertMayDecide() renders when a departmental step exists.
     * Against today's seeded chain it is a no-op passthrough.
     *
     * Public because a caller that must *explain* its refusal needs this rule
     * on its own. `canApprove()` answers "show the button?" and may collapse
     * every reason into one boolean; a service raising
     * ForbiddenActionException may not, because the sentence is the product
     * behaviour (see ApprovalRefusalRenderingTest). Step-role match,
     * self-submission and "nothing pending" belong to ApprovalService, which
     * authors their sentences — so this deliberately returns true for them.
     *
     * That is also why a caller who does not hold the step's role passes here:
     * "only a department_head can approve this step" is more use to them than a
     * complaint about a department they were never eligible for.
     */
    public function respectsDepartmentScope(User $user, PurchaseRequest $pr): bool
    {
        $step = $this->currentPendingRecord($pr);
        if ($step === null
            || $step->role_slug !== self::DEPARTMENTAL_STEP_ROLE
            || ! in_array($step->role_slug, $this->approvalRoleSlugs($user), true)) {
            return true;
        }

        // A PR with no department has no departmental boundary to enforce, and
        // refusing here would strand it at step 1 with no eligible approver.
        // Requiring a department on every submission (not just generated ones)
        // is the real fix; see F-013 in this module's fix log.
        if ($pr->department_id === null) {
            return true;
        }

        return $this->departmentId($user) === (int) $pr->department_id;
    }

    /**
     * Authority for the step the request is actually waiting on.
     *
     * ApprovalService already enforces step-role match, self-submission and
     * "nothing pending" centrally for every workflow. The only rule it cannot
     * know is the department scope on the department_head step, so that is all
     * this adds. Gating this on general row visibility instead — as it first
     * did — denied every plant-wide chain role, because those roles hold no
     * departmental scope to be visible through.
     */
    private function mayActOnCurrentStep(User $user, PurchaseRequest $pr): bool
    {
        $step = $this->currentPendingRecord($pr);
        if ($step === null || ! in_array($step->role_slug, $this->approvalRoleSlugs($user), true)) {
            return false;
        }

        return $this->respectsDepartmentScope($user, $pr);
    }

    private function isChainParticipant(User $user, PurchaseRequest $pr): bool
    {
        $stepRoles = $this->plantWideStepRoles($user);
        if ($stepRoles === []) {
            return false;
        }

        return $this->currentRecords($pr)
            ->contains(static fn (ApprovalRecord $record): bool => in_array($record->role_slug, $stepRoles, true));
    }

    /**
     * The caller's approval roles minus the departmental one.
     *
     * A department head's reach is already decided by its department, so
     * letting the chain branch match the department_head step as well would
     * hand every head every other department's submitted requests.
     *
     * @return list<string>
     */
    private function plantWideStepRoles(User $user): array
    {
        return array_values(array_filter(
            $this->approvalRoleSlugs($user),
            static fn (string $slug): bool => $slug !== self::DEPARTMENTAL_STEP_ROLE,
        ));
    }

    /** @return Collection<int, ApprovalRecord> */
    private function currentRecords(PurchaseRequest $pr): Collection
    {
        // The relation is already constrained to is_current and ordered by
        // step_order, so a superseded attempt can never be read as live.
        return $pr->relationLoaded('approvalRecords')
            ? $pr->approvalRecords
            : $pr->approvalRecords()->get();
    }

    private function currentPendingRecord(PurchaseRequest $pr): ?ApprovalRecord
    {
        return $this->currentRecords($pr)
            ->where('action', 'pending')
            ->sortBy('step_order')
            ->first();
    }

    private function isGlobal(User $user): bool
    {
        // These are the seeded company-wide PR operators. An approve
        // permission alone does not grant company-wide row visibility.
        return in_array($user->role?->slug, ['system_admin', 'purchasing_officer'], true);
    }

    private function departmentId(User $user): ?int
    {
        if ($user->employee_id === null) {
            return null;
        }

        $departmentId = $user->relationLoaded('employee')
            ? $user->employee?->department_id
            : Employee::query()->whereKey($user->employee_id)->value('department_id');

        return $departmentId !== null ? (int) $departmentId : null;
    }
}
