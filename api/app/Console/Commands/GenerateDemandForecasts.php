<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SettingsService;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Forecasting\Services\ForecastingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Generate the next forecast horizon for active products without replacing manual overrides. */
class GenerateDemandForecasts extends Command
{
    protected $signature = 'forecasting:generate
        {--method=moving_avg : Computed method: moving_avg or weighted_avg}
        {--horizon= : Number of future months; defaults to forecasting settings}
        {--lookback= : Historical months; defaults to forecasting settings}';

    protected $description = 'Generate demand forecasts for the configured future horizon.';

    public function handle(ForecastingService $service, SettingsService $settings): int
    {
        $method = (string) $this->option('method');
        if (! in_array($method, DemandForecast::COMPUTED_METHODS, true)) {
            $this->error('Method must be moving_avg or weighted_avg.');

            return self::FAILURE;
        }

        $horizon = $this->option('horizon') !== null
            ? (int) $this->option('horizon')
            : $settings->requiredInt('forecasting.default_horizon_months', 1, 36);
        $lookback = $this->option('lookback') !== null
            ? (int) $this->option('lookback')
            : $settings->requiredInt('forecasting.default_lookback_months', 1, 60);

        if ($horizon < 1 || $horizon > 36 || $lookback < 1 || $lookback > 60) {
            $this->error('Horizon must be 1..36 months and lookback must be 1..60 months.');

            return self::FAILURE;
        }

        $written = $service->recomputeBatch(
            Carbon::now()->startOfMonth()->addMonthNoOverflow(),
            $horizon,
            $method,
            null,
            true,
            $lookback,
        );

        $this->info("Generated {$written} demand forecast rows.");

        return self::SUCCESS;
    }
}
