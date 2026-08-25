<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Policies;

use App\Common\Models\ApprovalDelegation;
use App\Common\Models\ApprovalRecord;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
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
     * The one workflow step that is scoped to a department.
     *
     * The seeded purchase_request chain is department_head → production_manager
     * → purchasing_officer → system_admin, i.e. CLAUDE.md's Staff → Dept Head →
     * Manager → Officer → VP. Only the first step belongs to a department; every
     * later step is a company-level office that must be able to act on any
     * department's request. Scoping them all to a department made every step
     * after the first unsatisfiable, so a submitted PR could never leave step 2.
     */
    private const DEPARTMENTAL_STEP_ROLE = 'department_head';

    /**
     * @param Builder<PurchaseRequest> $query
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
        return $user->hasPermission('purchasing.po.create') && $this->canView($user, $pr);
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
            'can_view'               => $this->canView($user, $pr),
            'can_update'             => $canManageDraft,
            'can_delete'             => $canManageDraft,
            'can_submit'             => $canManageDraft,
            'can_cancel'             => $this->canCancel($user, $pr),
            'can_approve'            => $this->canApprove($user, $pr),
            'can_reject'             => $this->canReject($user, $pr),
            'can_acknowledge_budget' => $this->canAcknowledgeBudget($user, $pr),
            'can_convert'            => $this->canConvert($user, $pr),
            'can_print'              => $this->canView($user, $pr),
        ];
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

        if ($step->role_slug !== self::DEPARTMENTAL_STEP_ROLE) {
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
