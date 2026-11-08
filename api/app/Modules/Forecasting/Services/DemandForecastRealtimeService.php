<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Services;

use App\Common\Services\OutboxService;
use App\Modules\Forecasting\Events\DemandForecastChanged;
use App\Modules\Forecasting\Models\DemandForecast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces Forecasting record writes into private after-commit refresh signals. */
final class DemandForecastRealtimeService
{
    private static int $batchDepth = 0;

    private static bool $batchChanged = false;

    /** Defer a multi-row recompute to one list/dashboard refresh after all writes settle. */
    public static function coalesceBatch(callable $operation): mixed
    {
        $isOutermost = self::$batchDepth === 0;
        if ($isOutermost) {
            self::$batchChanged = false;
        }
        self::$batchDepth++;

        try {
            return $operation();
        } finally {
            self::$batchDepth--;

            if ($isOutermost) {
                $changed = self::$batchChanged;
                self::$batchChanged = false;
                if ($changed) {
                    app(self::class)->stageAggregate();
                }
            }
        }
    }

    public function stage(DemandForecast $forecast, string $action): void
    {
        if (self::$batchDepth > 0) {
            self::$batchChanged = true;

            return;
        }

        $this->persist(
            (string) $forecast->hash_id,
            $action,
            $forecast->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            (string) $forecast->getKey(),
        );
    }

    private function stageAggregate(): void
    {
        $this->persist(null, 'updated', now()->format('Y-m-d\TH:i:s.uP'), 'batch');
    }

    private function persist(?string $forecastHashId, string $action, string $version, string $scope): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new DemandForecastChanged($forecastHashId, $action, $version),
            "demand-forecast-refresh:{$scope}:{$transactionId}",
        );
    }
}
