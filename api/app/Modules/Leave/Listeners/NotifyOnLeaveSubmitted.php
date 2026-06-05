<?php

declare(strict_types=1);

namespace App\Modules\Leave\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Events\LeaveRequestSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnLeaveSubmitted implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(LeaveRequestSubmitted $event): void
    {
        try {
            $req = $event->leaveRequest->loadMissing(['employee', 'leaveType']);
            $emp = $req->employee;

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'department_head'))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'leave.submitted', [
                'title'       => "Leave Request from {$emp->full_name}",
                'message'     => "{$req->leaveType?->name} — {$req->days} day(s) from {$req->start_date->format('M j')} to {$req->end_date->format('M j')}.",
                'link_to'     => "/hr/leaves/{$req->hash_id}",
                'entity_type' => 'leave_request',
                'entity_id'   => $req->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLeaveSubmitted failed', ['error' => $e->getMessage()]);
        }
    }
}
