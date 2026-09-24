<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Support;

final class ForecastConfidence
{
    /** @param array<int, float|int> $values */
    public static function fromSeries(array $values): ?float
    {
        $n = count($values);
        if ($n < 2) {
            return null;
        }

        $mean = array_sum($values) / $n;
        if ($mean <= 0) {
            return null;
        }

        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ((float) $value - $mean) ** 2;
        }

        return max(0.0, min(100.0, 100.0 - (100.0 * sqrt($variance / $n) / $mean)));
    }
}
