<?php

declare(strict_types=1);

namespace App\Modules\Leave\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Events\LeaveRequestPendingHR;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnLeavePendingHR implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications, private readonly ?SettingsService $settings = null) {}

    public function handle(LeaveRequestPendingHR $event): void
    {
        try {
            $req = $event->leaveRequest->loadMissing(['employee', 'leaveType']);
            $emp = $req->employee;

            $roles = array_values(array_filter((array) ($this->settings ?? app(SettingsService::class))->get('leave.pending_hr.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'leave.pending_hr', [
                'title'       => "Leave Needs HR Approval — {$emp->full_name}",
                'message'     => "{$req->leaveType?->name} — {$req->days} day(s). Dept head approved.",
                'link_to'     => "/hr/leaves/{$req->hash_id}",
                'entity_type' => 'leave_request',
                'entity_id'   => $req->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLeavePendingHR failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
