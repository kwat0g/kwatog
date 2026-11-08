<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Observers;

use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Services\MaintenanceScheduleRealtimeService;

final class MaintenanceScheduleRealtimeObserver
{
    public function created(MaintenanceSchedule $schedule): void
    {
        $this->stage($schedule, 'created');
    }

    public function updated(MaintenanceSchedule $schedule): void
    {
        $this->stage($schedule, 'updated');
    }

    public function deleted(MaintenanceSchedule $schedule): void
    {
        $this->stage($schedule, 'deleted');
    }

    private function stage(MaintenanceSchedule $schedule, string $action): void
    {
        app(MaintenanceScheduleRealtimeService::class)->stage($schedule, $action);
    }
}
