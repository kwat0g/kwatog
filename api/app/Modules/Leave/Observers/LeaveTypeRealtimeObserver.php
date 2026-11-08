<?php

declare(strict_types=1);

namespace App\Modules\Leave\Observers;

use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveTypeRealtimeService;

/** Keep leave catalog changes on the durable after-commit refresh path. */
final class LeaveTypeRealtimeObserver
{
    public function created(LeaveType $leaveType): void
    {
        $this->changed($leaveType, 'created');
    }

    public function updated(LeaveType $leaveType): void
    {
        $this->changed($leaveType, 'updated');
    }

    public function deleted(LeaveType $leaveType): void
    {
        $this->changed($leaveType, 'deleted');
    }

    public function restored(LeaveType $leaveType): void
    {
        $this->changed($leaveType, 'restored');
    }

    private function changed(LeaveType $leaveType, string $action): void
    {
        app(LeaveTypeRealtimeService::class)->stage($leaveType, $action);
    }
}
