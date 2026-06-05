<?php

declare(strict_types=1);

namespace App\Modules\Leave\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Leave\Events\LeaveRequestApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnLeaveApproved implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(LeaveRequestApproved $event): void
    {
        try {
            $req  = $event->leaveRequest->loadMissing(['employee.user', 'leaveType']);
            $user = $req->employee?->user;

            if (! $user) {
                return;
            }

            $this->notifications->send($user, 'leave.approved', [
                'title'       => 'Leave Request Approved',
                'message'     => "Your {$req->leaveType?->name} ({$req->days} day(s)) from {$req->start_date->format('M j')} has been approved.",
                'link_to'     => "/self-service/leaves/{$req->hash_id}",
                'entity_type' => 'leave_request',
                'entity_id'   => $req->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLeaveApproved failed', ['error' => $e->getMessage()]);
        }
    }
}
