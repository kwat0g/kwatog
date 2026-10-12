<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Support;

/** Phase 3 (Task 7) — the ONE linear-regression trend-direction helper. `ForecastingDashboardService::computeTrend`, `CashFlowForecastService::trend` and `CapacityForecastService` all delegate here so the math cannot drift. */
final class ForecastTrend
{
    public static function direction(array $values, float $slopeRatio, float $minimumSlope): string
    {
        $n = count($values);
        if ($n < 3) {
            return 'stable';
        }
        $xMean = ($n - 1) / 2; $yMean = array_sum($values) / $n; $num = 0.0; $den = 0.0;
        foreach ($values as $i => $y) {
            $dx = $i - $xMean; $num += $dx * ($y - $yMean); $den += $dx * $dx;
        }
        if ($den === 0.0) {
            return 'stable';
        }
        $slope = $num / $den; $threshold = $yMean > 0 ? abs($slopeRatio * $yMean) : $minimumSlope;
        return $slope > $threshold ? 'up' : ($slope < -$threshold ? 'down' : 'stable');
    }
}
