<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ApprovalRecord;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Log;

class ApprovalEscalationService
{
    private const REMINDER_HOURS = 24;
    private const ESCALATE_HOURS = 48;

    public function __construct(private readonly NotificationService $notifications) {}

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
