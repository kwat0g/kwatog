<?php

declare(strict_types=1);

namespace App\Modules\Production\Observers;

use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Services\ProductionScheduleRealtimeService;

final class ProductionScheduleRealtimeObserver
{
    public function created(ProductionSchedule $schedule): void
    {
        $this->stage($schedule, 'created');
    }

    public function updated(ProductionSchedule $schedule): void
    {
        $this->stage($schedule, $schedule->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(ProductionSchedule $schedule): void
    {
        $this->stage($schedule, 'deleted');
    }

    private function stage(ProductionSchedule $schedule, string $action): void
    {
        app(ProductionScheduleRealtimeService::class)->stage($schedule, $action);
    }
}
