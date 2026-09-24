<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ApprovalRecord;
use App\Common\Models\WorkflowDefinition;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApprovalEscalationService
{
    /** Candidates that threw during the current run. */
    private int $failed = 0;

    /** Candidates whose configured role resolved to no active user. */
    private int $unstaffed = 0;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly \App\Common\Services\SettingsService $settings,
    ) {}

    /**
     * Run the whole sweep and report its health, not just its volume.
     *
     * The three run* methods below only ever returned the number of records
     * they touched: a candidate that threw was Log::warning'ed and not counted,
     * so a run where every record failed printed the same zeros as an idle run.
     * That is the byte-identical-to-idle blind spot that let the 8D SLA ledger
     * be dead for its entire life (see CLAUDE.md), and this sweep had it too.
     * `failed` counts candidates that threw; `unstaffed` counts candidates
     * whose role (and superior role, for escalations) has no active user, so a
     * misconfigured escalation chain is distinguishable from an idle one.
     *
     * @return array{reminders:int,escalations:int,auto_resolved:int,failed:int,unstaffed:int}
     */
    public function runWithOutcome(): array
    {
        $this->failed = 0;
        $this->unstaffed = 0;

        $reminders = $this->runReminders();
        $escalations = $this->runEscalations();
        $autoResolved = $this->runAutoResolve();

        return [
            'reminders' => $reminders,
            'escalations' => $escalations,
            'auto_resolved' => $autoResolved,
            'failed' => $this->failed,
            'unstaffed' => $this->unstaffed,
        ];
    }

    public function runReminders(): int
    {
        $reminderHours = $this->positiveIntSetting('approvals.reminder_hours');
        $count = 0;
        $query = ApprovalRecord::query()
            ->where('action', 'pending')
            ->where('is_current', true)
            ->whereNull('reminder_sent_at')
            ->where('created_at', '<', now()->subHours($reminderHours));

        $query->chunkById(100, function ($stale) use (&$count): void {
            foreach ($stale as $rec) {
                try {
                    $approver = $this->resolveCurrentApprover($rec);
                    if ($approver === null) {
                        // No active holder of the step role: nothing to remind.
                        // Leave reminder_sent_at null so a later run notifies
                        // once the role has an active user again.
                        $this->unstaffed++;

                        continue;
                    }

                    $hours = (int) abs(now()->diffInHours($rec->created_at));
                    $this->notifications->send($approver, 'approval_reminder', [
                        'title'   => 'Approval Reminder',
                        'message' => "Approval pending for {$hours}h on "
                                     .class_basename((string) $rec->approvable_type).".",
                        'link_to' => $this->linkFor($rec),
                    ], $this->notificationKey($rec, 'reminder'));
                    $rec->update(['reminder_sent_at' => now()]);
                    $count++;
                } catch (\Throwable $e) {
                    $this->failed++;
                    Log::warning('ApprovalEscalationService::reminder failed', [
                        'record_id' => $rec->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        });
        return $count;
    }

    public function runEscalations(): int
    {
        $escalationHours = $this->positiveIntSetting('approvals.escalation_hours');
        $count = 0;
        $query = ApprovalRecord::query()
            ->where('action', 'pending')
            ->where('is_current', true)
            ->whereNull('escalated_at')
            ->where('created_at', '<', now()->subHours($escalationHours));

        $query->chunkById(100, function ($stale) use (&$count): void {
            foreach ($stale as $rec) {
                try {
                    $approver = $this->resolveCurrentApprover($rec);
                    $superior = $this->resolveSuperior($rec);
                    $hours = (int) abs(now()->diffInHours($rec->created_at));

                    $recipients = collect([$approver, $superior])->filter()->unique('id');
                    if ($recipients->isEmpty()) {
                        // Neither the step role nor its configured superior has
                        // an active user. Don't stamp escalated_at — that would
                        // silence the record for good — just report it.
                        $this->unstaffed++;

                        continue;
                    }

                    $this->notifications->send($recipients, 'approval_escalation', [
                        'title'   => 'Approval Escalation',
                        'message' => "Escalation: approval pending {$hours}h on "
                                     .class_basename((string) $rec->approvable_type).".",
                        'link_to' => $this->linkFor($rec),
                    ], $this->notificationKey($rec, 'escalation'));

                    $rec->update([
                        'escalated_at'         => now(),
                        'escalated_to_user_id' => $superior?->id,
                    ]);
                    $count++;
                } catch (\Throwable $e) {
                    $this->failed++;
                    Log::warning('ApprovalEscalationService::escalate failed', [
                        'record_id' => $rec->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        });
        return $count;
    }

    /**
     * T1.6 — Auto-resolve approval records that have been escalated for too long.
     * Returns the count of records auto-resolved.
     */
    public function runAutoResolve(): int
    {
        $enabled = $this->settings->get('approvals.auto_resolve.enabled');
        if (! is_bool($enabled)) {
            throw new \App\Common\Exceptions\BusinessRuleException('Required business setting approvals.auto_resolve.enabled is missing or invalid.');
        }
        if (! $enabled) {
            return 0;
        }

        $defaultHours = $this->positiveIntSetting('approvals.auto_resolve.default_hours');
        $defaultAction = (string) $this->settings->get('approvals.auto_resolve.default_action');
        if (! in_array($defaultAction, ['approve', 'reject', 'escalate'], true)) {
            throw new \App\Common\Exceptions\BusinessRuleException('Required business setting approvals.auto_resolve.default_action is missing or invalid.');
        }

        $count = 0;
        $query = ApprovalRecord::query()
            ->where('action', 'pending')
            ->where('is_current', true)
            ->whereNotNull('escalated_at')
            ->whereNull('auto_resolved_at');

        $query->chunkById(100, function ($stale) use (&$count, $defaultHours, $defaultAction): void {
            foreach ($stale as $rec) {
                try {
                    [$hours, $action] = $this->resolvePolicyForRecord($rec, $defaultHours, $defaultAction);
                    if ($hours <= 0) continue;
                    // Carbon 2: parsing the past instant and diffing to now() returns
                    // a positive hour count when escalated_at is in the past.
                    $elapsed = \Carbon\Carbon::parse($rec->escalated_at)->diffInHours(now(), true);
                    if ($elapsed < $hours) {
                        continue;
                    }
                    $this->autoResolveRecord($rec, $action);
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning('ApprovalEscalationService::autoResolve failed', [
                        'record_id' => $rec->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        });
        return $count;
    }

    private function positiveIntSetting(string $key): int
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new \App\Common\Exceptions\BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }

        return (int) $value;
    }

    /**
     * Find the workflow step matching this record and read its
     * `auto_resolve_after_hours` and `auto_resolve_action`. Falls back to
     * the supplied defaults.
     *
     * @return array{0:int, 1:string} [hours, action]
     */
    private function resolvePolicyForRecord(ApprovalRecord $rec, int $defaultHours, string $defaultAction): array
    {
        $hours  = $defaultHours;
        $action = $defaultAction;

        $steps = is_array($rec->workflow_snapshot)
            ? ($rec->workflow_snapshot['steps'] ?? null)
            : null;

        if (! is_array($steps) && $rec->workflow_definition_id !== null) {
            $steps = WorkflowDefinition::query()
                ->whereKey($rec->workflow_definition_id)
                ->value('steps');
            if (is_string($steps)) {
                $steps = json_decode($steps, true);
            }
        }

        if (! is_array($steps)) {
            // Legacy rows have no workflow identity. A unique match is safe;
            // ambiguous matches must use the global policy rather than an
            // arbitrary definition.
            $matches = [];
            foreach (WorkflowDefinition::query()->where('is_active', true)->get() as $def) {
                foreach (($def->steps ?? []) as $step) {
                    if ((int) ($step['order'] ?? 0) === (int) $rec->step_order
                        && (string) ($step['role'] ?? '') === (string) $rec->role_slug
                    ) {
                        $matches[] = $step;
                        break;
                    }
                }
            }
            if (count($matches) === 1) {
                $steps = [$matches[0]];
            } elseif (count($matches) > 1) {
                Log::warning('ApprovalEscalationService: ambiguous legacy workflow policy', [
                    'record_id' => $rec->id,
                    'step_order' => $rec->step_order,
                    'role_slug' => $rec->role_slug,
                    'match_count' => count($matches),
                ]);
            }
        }

        foreach ($steps ?? [] as $step) {
            if ((int) ($step['order'] ?? 0) === (int) $rec->step_order
                && (string) ($step['role'] ?? '') === (string) $rec->role_slug
            ) {
                if (isset($step['auto_resolve_after_hours'])) {
                    $hours = (int) $step['auto_resolve_after_hours'];
                }
                if (isset($step['auto_resolve_action'])) {
                    $action = (string) $step['auto_resolve_action'];
                }
                break;
            }
        }

        if (! in_array($action, ['approve', 'reject', 'escalate'], true)) {
            $action = $defaultAction;
        }
        return [$hours, $action];
    }

    private function autoResolveRecord(ApprovalRecord $rec, string $action): void
    {
        // OGAMI-013 — 'escalate' is the safe, non-destructive resolution: do NOT
        // terminate the record. Re-notify the superior, stamp escalated_at so the
        // SLA clock restarts, and leave the step pending so a human still decides.
        if ($action === 'escalate') {
            $superior = $this->resolveSuperior($rec);
            $approver = $this->resolveCurrentApprover($rec);
            $hours    = (int) abs(now()->diffInHours($rec->created_at));

            $recipients = collect([$approver, $superior])->filter()->unique('id');
            $this->notifications->send($recipients, 'approval_escalation', [
                'title'   => 'Approval SLA Escalation',
                'message' => "SLA escalation: approval still pending {$hours}h on "
                             .class_basename((string) $rec->approvable_type)
                             .". Routed to superior for action.",
                'link_to' => $this->linkFor($rec),
            ], $this->notificationKey($rec, 'auto-escalation').':'.(string) $rec->escalated_at);

            $rec->update([
                'escalated_at'         => now(),
                'escalated_to_user_id' => $superior?->id,
                // intentionally NOT setting auto_resolved_at — the record stays
                // pending and eligible for the next SLA sweep.
            ]);
            return;
        }

        $actorRoles = array_values(array_filter((array) $this->settings->get('system.automation.actor_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
        $systemUser = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $actorRoles))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($systemUser === null) {
            throw new \App\Common\Exceptions\BusinessRuleException(
                'Approval auto-resolution requires an active automation actor.',
            );
        }

        DB::transaction(function () use ($rec, $action, $systemUser) {
            $rec->update([
                'approver_id'      => $systemUser->id,
                'action'           => $action === 'approve' ? 'approved' : 'rejected',
                'remarks'          => 'Auto-resolved by SLA policy.',
                'acted_at'         => now(),
                'auto_resolved_at' => now(),
            ]);

            if ($action === 'reject') {
                ApprovalRecord::query()
                    ->where('approvable_type', $rec->approvable_type)
                    ->where('approvable_id', $rec->approvable_id)
                    ->where('is_current', true)
                    ->where('step_order', '>', $rec->step_order)
                    ->where('action', 'pending')
                    ->update(['action' => 'skipped', 'acted_at' => now()]);
            }
        });
    }

    private function linkFor(ApprovalRecord $rec): string
    {
        $hashId = app('hashids')->encode((int) $rec->approvable_id);

        return ApprovalTypeRegistry::linkFor((string) $rec->approvable_type, $hashId);
    }

    private function notificationKey(ApprovalRecord $rec, string $kind): string
    {
        return sprintf(
            'approval:%s:%s:%d:%s',
            $kind,
            (string) $rec->approvable_type,
            (int) $rec->approvable_id,
            (int) $rec->step_order,
        );
    }

    private function resolveCurrentApprover(ApprovalRecord $rec): ?User
    {
        if ($rec->approver_id) {
            $u = User::find($rec->approver_id);
            if ($u && $u->is_active) return $u;
        }

        return User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', $rec->role_slug))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    private function resolveSuperior(ApprovalRecord $rec): ?User
    {
        $roleMap = (array) $this->settings->get('approvals.escalation.superior_role_map', []);
        $superiorRole = is_string($roleMap[$rec->role_slug] ?? null)
            ? $roleMap[$rec->role_slug]
            : null;
        if ($superiorRole === null || $superiorRole === '') return null;

        return User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', $superiorRole))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
