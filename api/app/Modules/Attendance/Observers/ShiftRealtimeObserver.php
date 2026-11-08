<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Observers;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftRealtimeService;

/** Captures shift lifecycle writes from API services and scheduled jobs. */
final class ShiftRealtimeObserver
{
    public function created(Shift $shift): void
    {
        $this->stage($shift, 'created');
    }

    public function updated(Shift $shift): void
    {
        $this->stage($shift, 'updated');
    }

    public function deleted(Shift $shift): void
    {
        $this->stage($shift, 'deleted');
    }

    public function restored(Shift $shift): void
    {
        $this->stage($shift, 'restored');
    }

    private function stage(Shift $shift, string $action): void
    {
        app(ShiftRealtimeService::class)->stage($shift, $action);
    }
}
