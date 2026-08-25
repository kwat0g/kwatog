<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Models\ApprovalRecord;
use App\Common\Models\WorkflowDefinition;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApprovalService
{
    /**
     * Submit a record into a workflow. Creates one pending approval_record per step.
     *
     * A step carrying a `threshold` is skipped (action = 'skipped') when the
     * supplied amount is strictly below it. The gate is the per-step `threshold`
     * key inside the `steps` JSON column, and that is now the only place a
     * threshold lives: a `workflow_definitions.amount_threshold` column used to
     * sit alongside it, seeded to the same 50000.00 on purchase_request and
     * purchase_order and read by nothing, until migration 0473 dropped it.
     *
     * $amount is a decimal string, never a float — it is compared against a
     * step threshold to decide whether that step is skipped, and a float
     * comparison at the boundary depends on binary representation.
     *
     * A step threshold lives in the `steps` JSON column as `threshold`. Store it
     * as a JSON **string** (`"50000.00"`): a JSON number is decoded to a PHP
     * float before this method ever sees it, so precision is lost upstream of
     * any comparison we can make here.
     */
    public function submit(Model $approvable, string $workflowType, ?string $amount = null): void
    {
        DB::transaction(function () use ($approvable, $workflowType, $amount) {
            // Serialize submissions for persisted approvables. The lightweight
            // transient model used by the unit suite has no backing table, so it
            // intentionally skips this optional lock.
            if ($approvable->exists && Schema::hasTable($approvable->getTable())) {
                $approvable->newQuery()
                    ->whereKey($approvable->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $workflow = WorkflowDefinition::where('workflow_type', $workflowType)
                ->where('is_active', true)
                ->firstOrFail();

            $type = $approvable->getMorphClass();
            $id = $approvable->getKey();
            $nextAttempt = ((int) ApprovalRecord::query()
                ->where('approvable_type', $type)
                ->where('approvable_id', $id)
                ->max('attempt')) + 1;

            // Keep every prior row for audit/history, but mark the old open
            // attempt as superseded so consumers filtering action=pending can
            // never act on a stale row. Terminal actions remain unchanged.
            ApprovalRecord::query()
                ->where('approvable_type', $type)
                ->where('approvable_id', $id)
                ->where('is_current', true)
                ->whereIn('action', ['pending', 'skipped'])
                ->update(['action' => 'superseded']);
            ApprovalRecord::query()
                ->where('approvable_type', $type)
                ->where('approvable_id', $id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $snapshot = [
                'workflow_type' => (string) $workflow->workflow_type,
                'name' => (string) $workflow->name,
                'steps' => array_values($workflow->steps ?? []),
            ];
            $version = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

            foreach ($workflow->steps as $step) {
                $threshold = isset($step['threshold']) ? (string) $step['threshold'] : null;
                $action = ($threshold !== null && $amount !== null && Money::lt($amount, $threshold))
                    ? 'skipped'
                    : 'pending';

                ApprovalRecord::create([
                    'approvable_type'      => $type,
                    'approvable_id'        => $id,
                    'step_order'           => (int) $step['order'],
                    'role_slug'            => (string) $step['role'],
                    'attempt'              => $nextAttempt,
                    'is_current'           => true,
                    'workflow_definition_id' => $workflow->id,
                    'workflow_version'     => $version,
                    'workflow_snapshot'    => $snapshot,
                    'action'               => $action,
                    'created_at'           => now(),
                ]);
            }
        });
    }

    /**
     * Both guards below raise ForbiddenActionException, not `abort(403, …)`.
     *
     * The refusals are identical in status and message, but the type is what
     * lets a caller name them. Every bulk approver here wraps each row in a
     * try/catch and puts the reason in front of the user; while these were
     * generic HTTP failures, LeaveRequestService had to guess from a status code
     * whether the sentence was authored copy, and narrowing that arm to
     * BusinessRuleException once dropped the segregation-of-duties sentence
     * entirely (f54822f7). See ForbiddenActionException for the full history.
     */
    public function approve(Model $approvable, User $user, ?string $remarks = null): void
    {
        DB::transaction(function () use ($approvable, $user, $remarks) {
            $next = $this->currentRecords($approvable)
                ->where('action', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $next) {
                throw new BusinessRuleException('Nothing pending to approve.');
            }
            $submitterId = $this->resolveSubmitterUserId($approvable);
            if ($submitterId !== null && $submitterId === $user->id) {
                throw new ForbiddenActionException('You cannot act on a record you submitted.');
            }
            if (! $this->userMayActFor($user, $next->role_slug)) {
                throw new ForbiddenActionException("Only users with role '{$next->role_slug}' can approve this step.");
            }

            $next->update([
                'approver_id' => $user->id,
                'action'      => 'approved',
                'remarks'     => $remarks,
                'acted_at'    => now(),
            ]);
        });
    }

    public function reject(Model $approvable, User $user, string $remarks): void
    {
        DB::transaction(function () use ($approvable, $user, $remarks) {
            $next = $this->currentRecords($approvable)
                ->where('action', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $next) {
                throw new BusinessRuleException('Nothing pending to reject.');
            }
            $submitterId = $this->resolveSubmitterUserId($approvable);
            if ($submitterId !== null && $submitterId === $user->id) {
                throw new ForbiddenActionException('You cannot act on a record you submitted.');
            }
            if (! $this->userMayActFor($user, $next->role_slug)) {
                throw new ForbiddenActionException("Only users with role '{$next->role_slug}' can reject this step.");
            }

            $next->update([
                'approver_id' => $user->id,
                'action'      => 'rejected',
                'remarks'     => $remarks,
                'acted_at'    => now(),
            ]);

            // Mark all subsequent pending steps as skipped.
            $this->currentRecords($approvable)
                ->where('step_order', '>', $next->step_order)
                ->where('action', 'pending')
                ->update(['action' => 'skipped', 'acted_at' => now()]);
        });
    }

    public function records(Model $approvable): \Illuminate\Database\Eloquent\Builder
    {
        return ApprovalRecord::where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->orderByDesc('attempt')
            ->orderBy('step_order')
            ->orderBy('id');
    }

    public function currentRecords(Model $approvable): \Illuminate\Database\Eloquent\Builder
    {
        return $this->records($approvable)->where('is_current', true);
    }

    public function nextStep(Model $approvable): ?ApprovalRecord
    {
        return $this->currentRecords($approvable)->where('action', 'pending')->first();
    }

    public function isFullyApproved(Model $approvable): bool
    {
        $records = $this->currentRecords($approvable)->get();
        if ($records->isEmpty()) return false;
        return $records->every(fn ($r) => in_array($r->action, ['approved', 'skipped'], true));
    }

    public function isRejected(Model $approvable): bool
    {
        return $this->currentRecords($approvable)->where('action', 'rejected')->exists();
    }

    /** @return Collection<int, ApprovalRecord> */
    public function chain(Model $approvable): Collection
    {
        return $this->records($approvable)->get();
    }

    /** @return Collection<int, ApprovalRecord> */
    public function currentChain(Model $approvable): Collection
    {
        return $this->currentRecords($approvable)->get();
    }

    private function userMayActFor(User $user, string $roleSlug): bool
    {
        // Direct role match — the original authority path.
        if ($user->role?->slug === $roleSlug) {
            return true;
        }

        // OGAMI-013 — delegation: allow when an active delegation (whose
        // [starts_at, ends_at] window covers now) grants this user the role.
        // The self-approval guard in approve()/reject() runs BEFORE this check,
        // so a delegate still cannot act on a record the delegator submitted.
        $delegates = \App\Common\Models\ApprovalDelegation::activeDelegatesFor($roleSlug, now());

        return in_array($user->id, $delegates, true);
    }

    /**
     * Resolve the user id of whoever submitted this approvable, so approve()
     * and reject() can refuse self-action. Returns null if the submitter cannot
     * be determined (in which case the self-approval guard does not fire).
     */
    private function resolveSubmitterUserId(Model $approvable): ?int
    {
        // Hook: model may implement approvalSubmitterId(): ?int
        if (method_exists($approvable, 'approvalSubmitterId')) {
            $id = $approvable->approvalSubmitterId();
            return $id !== null ? (int) $id : null;
        }
        foreach (['created_by', 'requested_by', 'submitted_by'] as $col) {
            if (isset($approvable->{$col}) && $approvable->{$col} !== null) {
                return (int) $approvable->{$col};
            }
        }
        return null; // unknown — guard cannot fire
    }
}
