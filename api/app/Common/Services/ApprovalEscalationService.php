<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ApprovalRecord;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Task A7 — Reminder + escalation for stale approvals.
 *
 *   T+24h  → ping current approver (reminder_sent_at stamped)
 *   T+48h  → ping current approver + admin/dept-head fallback (escalated_at)
 *
 * Never auto-approves. Each step is idempotent via the timestamp columns.
 */
class ApprovalEscalationService
{
    private const REMINDER_HOURS = 24;
    private const ESCALATE_HOURS = 48;

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
                    $this->notify($approver, 'approval_reminder', [
                        'approval_id'    => $rec->hash_id ?? null,
                        'approvable_type'=> class_basename((string) $rec->approvable_type),
                        'approvable_id'  => $rec->approvable_id,
                        'role_slug'      => $rec->role_slug,
                        'hours_overdue'  => $hours,
                        'message'        => "Approval pending for {$hours}h on ".class_basename((string) $rec->approvable_type)." #{$rec->approvable_id}.",
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

                $payload = [
                    'approval_id'    => $rec->hash_id ?? null,
                    'approvable_type'=> class_basename((string) $rec->approvable_type),
                    'approvable_id'  => $rec->approvable_id,
                    'role_slug'      => $rec->role_slug,
                    'hours_overdue'  => $hours,
                    'message'        => "Escalation: approval has been pending {$hours}h on "
                                       .class_basename((string) $rec->approvable_type)." #{$rec->approvable_id}.",
                ];

                if ($approver) {
                    $this->notify($approver, 'approval_escalation', $payload);
                }
                if ($superior) {
                    $this->notify($superior, 'approval_escalation', $payload);
                }

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

    private function resolveCurrentApprover(ApprovalRecord $rec): ?User
    {
        // First attempt: explicit approver_id (some workflows pre-assign).
        if ($rec->approver_id) {
            $u = User::find($rec->approver_id);
            if ($u && $u->is_active) return $u;
        }

        // Otherwise: pick any active user holding the role.
        return User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', $rec->role_slug))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    private function resolveSuperior(ApprovalRecord $rec): ?User
    {
        // Per-role hierarchy heuristic. Falls back to system_admin.
        $superiorRole = match ($rec->role_slug) {
            'department_head' => 'production_manager',
            'production_manager' => 'system_admin',
            'purchasing_officer' => 'system_admin',
            'finance_officer' => 'system_admin',
            'hr_officer' => 'system_admin',
            'ppc_head' => 'system_admin',
            default => 'system_admin',
        };

        return User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', $superiorRole))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    private function notify(User $u, string $type, array $data): void
    {
        $u->notifications()->create([
            'id'              => (string) Str::uuid(),
            'type'            => $type,
            'notifiable_type' => $u::class,
            'notifiable_id'   => $u->id,
            'data'            => $data,
            'read_at'         => null,
        ]);
    }
}
