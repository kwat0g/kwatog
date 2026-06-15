<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ApprovalRecord;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApprovalEscalationService
{
    private const REMINDER_HOURS = 24;
    private const ESCALATE_HOURS = 48;
    private const DEFAULT_AUTO_RESOLVE_HOURS  = 72;
    private const DEFAULT_AUTO_RESOLVE_ACTION = 'reject';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly \App\Common\Services\SettingsService $settings,
    ) {}

    public function runReminders(): int
    {
        $count = 0;
        $stale = ApprovalRecord::query()
            ->where('action', 'pending')
            ->whereNull('reminder_sent_at')
            ->where('created_at', '<', now()->subHours(self::REMINDER_HOURS))
            ->get();

        foreach ($stale as $rec) {
            try {
                $approver = $this->resolveCurrentApprover($rec);
                if ($approver) {
                    $hours = (int) abs(now()->diffInHours($rec->created_at));
                    $this->notifications->send($approver, 'approval_reminder', [
                        'title'   => 'Approval Reminder',
                        'message' => "Approval pending for {$hours}h on "
                                     .class_basename((string) $rec->approvable_type).".",
                        'link_to' => $this->linkFor($rec),
                    ]);
                }
                $rec->update(['reminder_sent_at' => now()]);
                $count++;
            } catch (\Throwable $e) {
                Log::warning('ApprovalEscalationService::reminder failed', [
                    'record_id' => $rec->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    public function runEscalations(): int
    {
        $count = 0;
        $stale = ApprovalRecord::query()
            ->where('action', 'pending')
            ->whereNull('escalated_at')
            ->where('created_at', '<', now()->subHours(self::ESCALATE_HOURS))
            ->get();

        foreach ($stale as $rec) {
            try {
                $approver = $this->resolveCurrentApprover($rec);
                $superior = $this->resolveSuperior($rec);
                $hours = (int) abs(now()->diffInHours($rec->created_at));

                $data = [
                    'title'   => 'Approval Escalation',
                    'message' => "Escalation: approval pending {$hours}h on "
                                 .class_basename((string) $rec->approvable_type).".",
                    'link_to' => $this->linkFor($rec),
                ];

                $recipients = collect([$approver, $superior])->filter()->unique('id');
                $this->notifications->send($recipients, 'approval_escalation', $data);

                $rec->update([
                    'escalated_at'         => now(),
                    'escalated_to_user_id' => $superior?->id,
                ]);
                $count++;
            } catch (\Throwable $e) {
                Log::warning('ApprovalEscalationService::escalate failed', [
                    'record_id' => $rec->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    /**
     * T1.6 — Auto-resolve approval records that have been escalated for too long.
     * Returns the count of records auto-resolved.
     */
    public function runAutoResolve(): int
    {
        if (! (bool) $this->settings->get('approvals.auto_resolve.enabled', false)) {
            return 0;
        }

        $defaultHours  = (int) $this->settings->get('approvals.auto_resolve.default_hours', self::DEFAULT_AUTO_RESOLVE_HOURS);
        $defaultAction = (string) $this->settings->get('approvals.auto_resolve.default_action', self::DEFAULT_AUTO_RESOLVE_ACTION);

        $count = 0;
        $stale = ApprovalRecord::query()
            ->where('action', 'pending')
            ->whereNotNull('escalated_at')
            ->whereNull('auto_resolved_at')
            ->get();

        foreach ($stale as $rec) {
            try {
                [$hours, $action] = $this->resolvePolicyForRecord($rec, $defaultHours, $defaultAction);
                if ($hours <= 0) continue;
                // Carbon 2: parsing the past instant and diffing to now() returns
                // a positive hour count when escalated_at is in the past.
                $elapsed = \Carbon\Carbon::parse($rec->escalated_at)->diffInHours(now());
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
        return $count;
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

        // Resolve the workflow_type by walking back to ApprovalService::submit's
        // contract: each approval_records row carries (approvable_type, role_slug,
        // step_order). We re-derive the workflow definition by matching role_slug
        // + step_order against any WorkflowDefinition. This is best-effort —
        // multiple workflow_types may share a role slug; in that case the first
        // matching workflow's policy wins. Acceptable for thesis scope; future
        // schema can stamp workflow_definition_id on the record.
        $defs = \App\Common\Models\WorkflowDefinition::query()->get();
        foreach ($defs as $def) {
            foreach (($def->steps ?? []) as $step) {
                if ((int) ($step['order'] ?? 0) === (int) $rec->step_order
                    && (string) ($step['role'] ?? '') === (string) $rec->role_slug
                ) {
                    if (isset($step['auto_resolve_after_hours'])) {
                        $hours = (int) $step['auto_resolve_after_hours'];
                    }
                    if (isset($step['auto_resolve_action'])) {
                        $action = (string) $step['auto_resolve_action'];
                    }
                    break 2;
                }
            }
        }

        if (! in_array($action, ['approve', 'reject'], true)) {
            $action = $defaultAction;
        }
        return [$hours, $action];
    }

    private function autoResolveRecord(ApprovalRecord $rec, string $action): void
    {
        $systemUser = User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        DB::transaction(function () use ($rec, $action, $systemUser) {
            $rec->update([
                'approver_id'      => $systemUser?->id,
                'action'           => $action === 'approve' ? 'approved' : 'rejected',
                'remarks'          => 'Auto-resolved by SLA policy.',
                'acted_at'         => now(),
                'auto_resolved_at' => now(),
            ]);

            if ($action === 'reject') {
                ApprovalRecord::query()
                    ->where('approvable_type', $rec->approvable_type)
                    ->where('approvable_id', $rec->approvable_id)
                    ->where('step_order', '>', $rec->step_order)
                    ->where('action', 'pending')
                    ->update(['action' => 'skipped', 'acted_at' => now()]);
            }
        });
    }

    private function linkFor(ApprovalRecord $rec): string
    {
        $typeMap = [
            'PurchaseRequest' => '/purchasing/purchase-requests/',
            'PurchaseOrder'   => '/purchasing/purchase-orders/',
            'LeaveRequest'    => '/hr/leaves/',
            'LoanApplication' => '/hr/loans/',
        ];

        $basename = class_basename((string) $rec->approvable_type);
        $prefix = $typeMap[$basename] ?? '/admin/audit-logs';
        $hashId = $rec->approvable?->hash_id ?? '';

        return $prefix . $hashId;
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
        $superiorRole = match ($rec->role_slug) {
            'department_head'    => 'production_manager',
            'production_manager' => 'system_admin',
            'purchasing_officer' => 'system_admin',
            'finance_officer'    => 'system_admin',
            'hr_officer'         => 'system_admin',
            'ppc_head'           => 'system_admin',
            default              => 'system_admin',
        };

        return User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', $superiorRole))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
