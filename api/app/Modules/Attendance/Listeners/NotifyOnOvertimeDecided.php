<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnOvertimeDecided implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(OvertimeRequestDecided $event): void
    {
        try {
            $ot    = $event->overtimeRequest->loadMissing('employee.user');
            $user  = $ot->employee?->user;

            if (! $user) {
                return;
            }

            $label = $event->approved ? 'Approved' : 'Rejected';
            $type  = $event->approved ? 'attendance.ot_approved' : 'attendance.ot_rejected';

            $this->notifications->send($user, $type, [
                'title'       => "Overtime Request {$label}",
                'message'     => "Your OT request ({$ot->hours_requested}h on {$ot->date->format('M j')}) was {$label}.",
                'link_to'     => "/self-service/overtime/{$ot->hash_id}",
                'entity_type' => 'overtime_request',
                'entity_id'   => $ot->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnOvertimeDecided failed', ['error' => $e->getMessage()]);
        }
    }
}
