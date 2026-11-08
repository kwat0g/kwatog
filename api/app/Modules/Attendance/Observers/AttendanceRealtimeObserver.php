<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Observers;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceRealtimeService;

/** Captures HTTP, device-punch, import, leave-processing and job Eloquent writes. */
final class AttendanceRealtimeObserver
{
    public function created(Attendance $attendance): void
    {
        app(AttendanceRealtimeService::class)->stage($attendance, 'created');
    }

    public function updated(Attendance $attendance): void
    {
        app(AttendanceRealtimeService::class)->stage($attendance, 'updated');
    }

    public function deleted(Attendance $attendance): void
    {
        app(AttendanceRealtimeService::class)->stage($attendance, 'deleted');
    }

    public function restored(Attendance $attendance): void
    {
        app(AttendanceRealtimeService::class)->stage($attendance, 'restored');
    }
}
