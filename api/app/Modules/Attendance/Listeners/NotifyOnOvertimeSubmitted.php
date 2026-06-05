<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnOvertimeSubmitted implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(OvertimeRequestSubmitted $event): void
    {
        try {
            $ot  = $event->overtimeRequest->loadMissing('employee');
            $emp = $ot->employee;

            $deptId = $emp?->department_id;

            // Only notify department head(s) in the requester's own department.
            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'department_head'))
                ->where('is_active', true)
                ->when($deptId, fn ($q) => $q->whereHas('employee', fn ($eq) => $eq->where('department_id', $deptId)))
                ->get();

            $this->notifications->send($audience, 'attendance.ot_submitted', [
                'title'       => "OT Request from {$emp->full_name}",
                'message'     => "{$ot->hours_requested}h on {$ot->date->format('M j, Y')}.",
                'link_to'     => "/hr/attendance/overtime/{$ot->hash_id}",
                'entity_type' => 'overtime_request',
                'entity_id'   => $ot->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnOvertimeSubmitted failed', ['error' => $e->getMessage()]);
        }
    }
}
