<?php

declare(strict_types=1);

namespace App\Modules\Leave\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Leave\Events\LeaveRequestApproved;
use App\Modules\Leave\Events\LeaveRequestRejected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnLeaveDecided implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handleApproved(LeaveRequestApproved $event): void
    {
        try {
            $req  = $event->leaveRequest->loadMissing(['employee', 'leaveType', 'employee.user']);
            $user = $req->employee?->user;

            if (! $user) {
                return;
            }

            $this->notifications->send(collect([$user]), 'leave.approved', [
                'title'       => 'Leave Request Approved',
                'message'     => "{$req->leaveType?->name} — {$req->days} day(s) from {$req->start_date->format('M j')} to {$req->end_date->format('M j')} has been approved.",
                'link_to'     => "/self-service/leaves/{$req->hash_id}",
                'entity_type' => 'leave_request',
                'entity_id'   => $req->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLeaveDecided::handleApproved failed', ['error' => $e->getMessage()]);
        }
    }

    public function handleRejected(LeaveRequestRejected $event): void
    {
        try {
            $req  = $event->leaveRequest->loadMissing(['employee', 'leaveType', 'employee.user']);
            $user = $req->employee?->user;

            if (! $user) {
                return;
            }

            $this->notifications->send(collect([$user]), 'leave.rejected', [
                'title'       => 'Leave Request Rejected',
                'message'     => "{$req->leaveType?->name} — {$req->days} day(s) from {$req->start_date->format('M j')} to {$req->end_date->format('M j')} has been rejected.",
                'link_to'     => "/self-service/leaves/{$req->hash_id}",
                'entity_type' => 'leave_request',
                'entity_id'   => $req->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLeaveDecided::handleRejected failed', ['error' => $e->getMessage()]);
        }
    }
}
