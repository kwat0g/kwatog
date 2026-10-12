<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Common\Models\ApprovalDelegation;
use App\Common\Models\ApprovalRecord;
use App\Common\Models\WorkflowDefinition;
use App\Common\Support\ApprovalTypeRegistry;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApprovalService
{
    private const NOTIFY_ON_SUBMIT = ['pr', 'bill_payment', 'salary_adjustment', 'asset_disposal', 'return_request'];

    private const NOTIFY_HANDOFF = ['pr', 'po', 'loan', 'salary_adjustment', 'asset_disposal', 'return_request'];

    private const NOTIFY_OUTCOME = ['bill_payment', 'salary_adjustment', 'asset_disposal'];

    public function __construct(private readonly NotificationService $notifications) {}

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
                ->get()
                ->each(static fn (ApprovalRecord $record) => $record->update(['action' => 'superseded']));
            ApprovalRecord::query()
                ->where('approvable_type', $type)
                ->where('approvable_id', $id)
                ->where('is_current', true)
                ->get()
                ->each(static fn (ApprovalRecord $record) => $record->update(['is_current' => false]));

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
                    'approvable_type' => $type,
                    'approvable_id' => $id,
                    'step_order' => (int) $step['order'],
                    'role_slug' => (string) $step['role'],
                    'attempt' => $nextAttempt,
                    'is_current' => true,
                    'workflow_definition_id' => $workflow->id,
                    'workflow_version' => $version,
                    'workflow_snapshot' => $snapshot,
                    'action' => $action,
                    'created_at' => now(),
                ]);
            }

            if (in_array($workflowType, self::NOTIFY_ON_SUBMIT, true)) {
                $pending = $this->currentRecords($approvable)->where('action', 'pending')->orderBy('step_order')->first();
                if ($pending) {
                    $this->notifyActionRequired($approvable, $pending);
                }
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
            $this->assertNewDecisionMaker($approvable, $user);
            if (! $this->userMayActFor($user, $next->role_slug)) {
                throw new ForbiddenActionException("Only users with role '{$next->role_slug}' can approve this step.");
            }

            $next->update([
                'approver_id' => $user->id,
                'action' => 'approved',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            $following = $this->currentRecords($approvable)->where('action', 'pending')->orderBy('step_order')->first();
            $meta = ApprovalTypeRegistry::forClass($approvable->getMorphClass());
            if ($following && $meta && in_array($meta['kind'], self::NOTIFY_HANDOFF, true)) {
                $this->notifyActionRequired($approvable, $following);
            } elseif ($meta && in_array($meta['kind'], self::NOTIFY_OUTCOME, true)
                && $this->isFullyApproved($approvable)) {
                $this->notifySubmitter($approvable, $next, 'approval.completed', 'Approval completed');
            }
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
            $this->assertNewDecisionMaker($approvable, $user);
            if (! $this->userMayActFor($user, $next->role_slug)) {
                throw new ForbiddenActionException("Only users with role '{$next->role_slug}' can reject this step.");
            }

            $next->update([
                'approver_id' => $user->id,
                'action' => 'rejected',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            // Mark all subsequent pending steps as skipped.
            $this->currentRecords($approvable)
                ->where('step_order', '>', $next->step_order)
                ->where('action', 'pending')
                ->get()
                ->each(static fn (ApprovalRecord $record) => $record->update([
                    'action' => 'skipped',
                    'acted_at' => now(),
                ]));

            $meta = ApprovalTypeRegistry::forClass($approvable->getMorphClass());
            if ($meta && in_array($meta['kind'], self::NOTIFY_OUTCOME, true)) {
                $this->notifySubmitter($approvable, $next, 'approval.rejected', 'Approval rejected');
            }
        });
    }

    public function records(Model $approvable): Builder
    {
        return ApprovalRecord::where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->orderByDesc('attempt')
            ->orderBy('step_order')
            ->orderBy('id');
    }

    public function currentRecords(Model $approvable): Builder
    {
        return $this->records($approvable)->where('is_current', true);
    }

    public function nextStep(Model $approvable): ?ApprovalRecord
    {
        return $this->currentRecords($approvable)->where('action', 'pending')->first();
    }

    public function notifyNextStep(Model $approvable): void
    {
        $next = $this->nextStep($approvable);
        if ($next) {
            $this->notifyActionRequired($approvable, $next);
        }
    }

    public function isFullyApproved(Model $approvable): bool
    {
        $records = $this->currentRecords($approvable)->get();
        if ($records->isEmpty()) {
            return false;
        }

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
        $currentUser = $user->fresh(['role']);
        if (! $currentUser || ! $currentUser->is_active) {
            return false;
        }

        // Direct role match — the original authority path.
        if ($currentUser->role?->slug === $roleSlug) {
            return true;
        }

        // OGAMI-013 — delegation: allow when an active delegation (whose
        // [starts_at, ends_at] window covers now) grants this user the role.
        // The self-approval guard in approve()/reject() runs BEFORE this check,
        // so a delegate still cannot act on a record the delegator submitted.
        $delegates = ApprovalDelegation::activeDelegatesFor($roleSlug, now());

        return in_array($currentUser->id, $delegates, true);
    }

    private function assertNewDecisionMaker(Model $approvable, User $user): void
    {
        $alreadyActed = $this->currentRecords($approvable)
            ->where('approver_id', $user->id)
            ->whereIn('action', ['approved', 'rejected'])
            ->exists();

        if ($alreadyActed) {
            throw new ForbiddenActionException('A different user must act at each approval step.');
        }
    }

    public function canUserActFor(User $user, string $roleSlug): bool
    {
        return $this->userMayActFor($user, $roleSlug);
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

    private function notifyActionRequired(Model $approvable, ApprovalRecord $record): void
    {
        $meta = ApprovalTypeRegistry::forClass($approvable->getMorphClass());
        if ($meta === null) {
            return;
        }

        $delegateIds = ApprovalDelegation::activeDelegatesFor($record->role_slug, now());
        $recipients = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($record, $delegateIds): void {
                $query->whereHas('role', fn ($role) => $role->where('slug', $record->role_slug));
                if ($delegateIds !== []) {
                    $query->orWhereIn('id', $delegateIds);
                }
            });
        $submitterId = $this->resolveSubmitterUserId($approvable);
        if ($submitterId !== null) {
            $recipients->where('id', '<>', $submitterId);
        }
        $recipients = $recipients->get();
        if ($recipients->isEmpty()) {
            return;
        }

        [$hashId, $link] = $this->approvalLink($approvable, $meta);
        $number = $meta['number'] ? $approvable->getAttribute($meta['number']) : null;
        $reference = is_string($number) && $number !== '' ? " {$number}" : '';
        $this->notifications->send($recipients, 'approval.action_required', [
            'title' => "{$meta['label']} approval needed",
            'message' => "{$meta['label']}{$reference} is waiting for your approval.",
            'link_to' => $link,
            'entity_type' => class_basename($approvable),
            'entity_id' => $hashId,
        ], "approval:action-required:{$approvable->getMorphClass()}:{$approvable->getKey()}:{$record->attempt}:{$record->step_order}");
    }

    private function notifySubmitter(Model $approvable, ApprovalRecord $record, string $type, string $title): void
    {
        $submitterId = $this->resolveSubmitterUserId($approvable);
        $submitter = $submitterId === null ? null : User::query()->whereKey($submitterId)->where('is_active', true)->first();
        $meta = ApprovalTypeRegistry::forClass($approvable->getMorphClass());
        if ($submitter === null || $meta === null) {
            return;
        }

        [$hashId, $link] = $this->approvalLink($approvable, $meta);
        $number = $meta['number'] ? $approvable->getAttribute($meta['number']) : null;
        $reference = is_string($number) && $number !== '' ? " {$number}" : '';
        $message = "{$meta['label']}{$reference} was ".($type === 'approval.rejected' ? 'rejected.' : 'approved.');
        $this->notifications->send($submitter, $type, [
            'title' => $title,
            'message' => $message,
            'link_to' => $link,
            'entity_type' => class_basename($approvable),
            'entity_id' => $hashId,
        ], "approval:outcome:{$approvable->getMorphClass()}:{$approvable->getKey()}:{$record->attempt}:{$type}");
    }

    /** @param array<string, mixed> $meta @return array{0:string,1:string} */
    private function approvalLink(Model $approvable, array $meta): array
    {
        $hashId = method_exists($approvable, 'getHashIdAttribute')
            ? (string) $approvable->hash_id
            : app('hashids')->encode((int) $approvable->getKey());
        $parentRelation = $meta['link_parent'] ?? null;
        $parentHashId = $parentRelation ? $approvable->{$parentRelation}?->hash_id : null;

        return [$hashId, ApprovalTypeRegistry::linkFor($approvable->getMorphClass(), $hashId, $parentHashId)];
    }
}
