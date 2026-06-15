<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ApprovalRecord;
use App\Common\Models\WorkflowDefinition;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApprovalService
{
    /**
     * Submit a record into a workflow. Creates one pending approval_record per step.
     *
     * Steps that are gated by `amount_threshold` are skipped (status = 'skipped')
     * if the supplied amount is below the threshold.
     */
    public function submit(Model $approvable, string $workflowType, ?float $amount = null): void
    {
        DB::transaction(function () use ($approvable, $workflowType, $amount) {
            $workflow = WorkflowDefinition::where('workflow_type', $workflowType)->firstOrFail();

            // Resubmission: keep approved/rejected rows as audit history. Pending and
            // skipped rows are cleared so the new attempt starts clean. This may yield
            // multiple rows at the same step_order (one historical, one current);
            // callers reading the chain should treat the latest non-terminal record
            // per step_order as authoritative.
            ApprovalRecord::where('approvable_type', $approvable->getMorphClass())
                ->where('approvable_id', $approvable->getKey())
                ->whereIn('action', ['pending', 'skipped'])
                ->delete();

            foreach ($workflow->steps as $step) {
                $threshold = isset($step['threshold']) ? (float) $step['threshold'] : null;
                $action = ($threshold !== null && $amount !== null && $amount < $threshold)
                    ? 'skipped'
                    : 'pending';

                ApprovalRecord::create([
                    'approvable_type' => $approvable->getMorphClass(),
                    'approvable_id'   => $approvable->getKey(),
                    'step_order'      => (int) $step['order'],
                    'role_slug'       => (string) $step['role'],
                    'action'          => $action,
                    'created_at'      => now(),
                ]);
            }
        });
    }

    public function approve(Model $approvable, User $user, ?string $remarks = null): void
    {
        DB::transaction(function () use ($approvable, $user, $remarks) {
            $next = $this->records($approvable)
                ->where('action', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $next) {
                throw new RuntimeException('Nothing pending to approve.');
            }
            $submitterId = $this->resolveSubmitterUserId($approvable);
            if ($submitterId !== null && $submitterId === $user->id) {
                abort(403, 'You cannot act on a record you submitted.');
            }
            if (! $this->userMayActFor($user, $next->role_slug)) {
                abort(403, "Only users with role '{$next->role_slug}' can approve this step.");
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
            $next = $this->records($approvable)
                ->where('action', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $next) {
                throw new RuntimeException('Nothing pending to reject.');
            }
            $submitterId = $this->resolveSubmitterUserId($approvable);
            if ($submitterId !== null && $submitterId === $user->id) {
                abort(403, 'You cannot act on a record you submitted.');
            }
            if (! $this->userMayActFor($user, $next->role_slug)) {
                abort(403, "Only users with role '{$next->role_slug}' can reject this step.");
            }

            $next->update([
                'approver_id' => $user->id,
                'action'      => 'rejected',
                'remarks'     => $remarks,
                'acted_at'    => now(),
            ]);

            // Mark all subsequent pending steps as skipped.
            $this->records($approvable)
                ->where('step_order', '>', $next->step_order)
                ->where('action', 'pending')
                ->update(['action' => 'skipped', 'acted_at' => now()]);
        });
    }

    public function records(Model $approvable): \Illuminate\Database\Eloquent\Builder
    {
        return ApprovalRecord::where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->orderBy('step_order');
    }

    public function nextStep(Model $approvable): ?ApprovalRecord
    {
        return $this->records($approvable)->where('action', 'pending')->first();
    }

    public function isFullyApproved(Model $approvable): bool
    {
        $records = $this->records($approvable)->get();
        if ($records->isEmpty()) return false;
        return $records->every(fn ($r) => in_array($r->action, ['approved', 'skipped'], true));
    }

    public function isRejected(Model $approvable): bool
    {
        return $this->records($approvable)->where('action', 'rejected')->exists();
    }

    /** @return Collection<int, ApprovalRecord> */
    public function chain(Model $approvable): Collection
    {
        return $this->records($approvable)->get();
    }

    private function userMayActFor(User $user, string $roleSlug): bool
    {
        return $user->role?->slug === $roleSlug;
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
