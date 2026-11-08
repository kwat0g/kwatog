<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Observers;

use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Services\HolidayRealtimeService;

/** Stages one durable refresh signal for every holiday lifecycle write. */
final class HolidayRealtimeObserver
{
    public function created(Holiday $holiday): void
    {
        $this->changed($holiday, 'created');
    }

    public function updated(Holiday $holiday): void
    {
        $this->changed($holiday, 'updated');
    }

    public function deleted(Holiday $holiday): void
    {
        $this->changed($holiday, 'deleted');
    }

    public function restored(Holiday $holiday): void
    {
        $this->changed($holiday, 'restored');
    }

    private function changed(Holiday $holiday, string $action): void
    {
        app(HolidayRealtimeService::class)->stage($holiday, $action);
    }
}
