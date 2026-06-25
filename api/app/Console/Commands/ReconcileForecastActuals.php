<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Forecasting\Services\ForecastingService;
use Illuminate\Console\Command;

class ReconcileForecastActuals extends Command
{
    protected $signature = 'forecasting:reconcile-actuals';
    protected $description = 'Backfill actual_quantity and variance on elapsed forecast periods';

    public function handle(ForecastingService $service): int
    {
        $updated = $service->reconcileActuals();
        $this->info("Reconciled {$updated} forecast periods.");
        return self::SUCCESS;
    }
}
