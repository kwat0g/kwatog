<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Observers;

use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Forecasting\Services\DemandForecastRealtimeService;

final class DemandForecastRealtimeObserver
{
    public function created(DemandForecast $forecast): void
    {
        $this->stage($forecast, 'created');
    }

    public function updated(DemandForecast $forecast): void
    {
        $this->stage($forecast, 'updated');
    }

    public function deleted(DemandForecast $forecast): void
    {
        $this->stage($forecast, 'deleted');
    }

    private function stage(DemandForecast $forecast, string $action): void
    {
        app(DemandForecastRealtimeService::class)->stage($forecast, $action);
    }
}
