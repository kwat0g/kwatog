<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\NotificationService;
use App\Modules\Attendance\Services\EmployeeAvailabilityService;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Listeners\NotifyMaintenanceWorkOrder;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceAssigneeSuggestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckMaintenanceAssignment extends Command
{
    protected $signature = 'maintenance:check-assignment';

    protected $description = 'Escalate unassigned critical maintenance and suggest shift-end handovers';

    public function handle(NotificationService $notifications, EmployeeAvailabilityService $availability,
        MaintenanceAssigneeSuggestionService $suggestions): int
    {
        $recipients = User::query()->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['maintenance_supervisor', 'production_manager']))
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission('maintenance.view'))
            ->values();
        $failed = false;
        MaintenanceWorkOrder::query()->where('priority', 'critical')->where('status', 'open')
            ->whereNull('assigned_to')->where('created_at', '<=', now()->subMinutes(15))
            ->chunkById(100, function ($orders) use ($recipients, $notifications, &$failed): void {
                foreach ($orders as $order) {
                    DB::transaction(function () use ($order, $recipients, $notifications, &$failed): void {
                        $locked = MaintenanceWorkOrder::query()->lockForUpdate()->find($order->id);
                        if (! $locked || $locked->priority->value !== 'critical' || $locked->status->value !== 'open'
                            || $locked->assigned_to !== null || $locked->created_at->gt(now()->subMinutes(15))) {
                            return;
                        }
                        if (! $recipients->contains(fn (User $user) => $user->role->slug === 'maintenance_supervisor')
                            || ! $recipients->contains(fn (User $user) => $user->role->slug === 'production_manager')) {
                            Log::error('Maintenance escalation setup failure: supervisor or production manager account missing.', ['work_order_id' => $locked->id]);
                            $failed = true;
                        }
                        // shortcut: assignment is irreversible in this lifecycle; add an episode identifier if unassignment is introduced.
                        $notifications->sendForSubject($locked, $recipients, 'maintenance.assignment_escalated', NotifyMaintenanceWorkOrder::payload(
                            $locked, 'Critical maintenance remains unassigned: '.$locked->mwo_number,
                            'This critical order has waited at least 15 minutes. Review and assign a technician.',
                        ), 'maintenance:unassigned:'.$locked->id);
                    });
                }
            });

        $supervisors = $recipients->filter(fn (User $user) => $user->role->slug === 'maintenance_supervisor');
        MaintenanceWorkOrder::query()->where('status', 'in_progress')->whereNotNull('assigned_to')
            ->whereNull('repair_submitted_at')->with('assignee')->chunkById(100,
                function ($orders) use ($availability, $suggestions, $supervisors, $notifications): void {
                    foreach ($orders as $order) {
                        $outgoing = $order->assignee;
                        $shift = $outgoing ? $availability->shiftInterval($outgoing, now()) : null;
                        if (! $shift || $shift['end']->lte(now()) || $shift['end']->gt(now()->addMinutes(30))) {
                            continue;
                        }
                        $replacement = collect($suggestions->suggest($order, now(), now()->addMinute())['candidates'])
                            ->first(fn (array $candidate) => $candidate['can_assign'] && $candidate['id'] !== $outgoing->hash_id);
                        if (! $replacement || $supervisors->isEmpty()) {
                            continue;
                        }
                        $notifications->sendForSubject($order, $supervisors, 'maintenance.shift_handover', NotifyMaintenanceWorkOrder::payload(
                            $order, 'Maintenance shift handover: '.$order->mwo_number,
                            $outgoing->full_name.' finishes at '.$shift['end']->format('H:i').'. '
                                .$replacement['name'].' is available. Review the handover before changing the assignee.',
                        ), 'maintenance:handover:'.$order->id.':'.$shift['end']->toIso8601String());
                    }
                });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
