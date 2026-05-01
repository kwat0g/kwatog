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

            // Wipe any prior records for this approvable (resubmission).
            ApprovalRecord::where('approvable_type', $approvable->getMorphClass())
                ->where('approvable_id', $approvable->getKey())
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
            $next = $this->nextStep($approvable);
            if (! $next) {
                throw new RuntimeException('Nothing pending to approve.');
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
            $next = $this->nextStep($approvable);
            if (! $next) {
                throw new RuntimeException('Nothing pending to reject.');
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
        return $user->role?->slug === $roleSlug || $user->role?->slug === 'system_admin';
    }
}
