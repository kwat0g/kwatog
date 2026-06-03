<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Forecasting dashboard panels (headcount, revenue, defect rate).
 *
 * Each method computes a simple moving-average forecast from historical data
 * and returns a uniform shape consumable by the SPA forecast-panel components.
 *
 * All queries throw ->safe* wrappers (Schema::hasTable) so new installs with
 * empty schemas degrade gracefully instead of crashing.
 */
class ForecastingDashboardService
{
    private const HISTORICAL_MONTHS = 6;
    private const FORECAST_MONTHS   = 6;

    /* ───────────────────────────────────────────────────────────────
     * 1. HR — Headcount forecast
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Return monthly headcount for the last N months + forecast for next N months.
     *
     * Headcount = employees with status='active' AND (date_hired <= month end)
     * AND NOT soft-deleted (deleted_at IS NULL OR > month end).
     *
     * @return array{historical: array, forecast: array, trend: string, kpi: array}
     */
    public function headcountForecast(): array
    {
        if (! Schema::hasTable('employees')) {
            return $this->emptyResponse();
        }

        $now    = Carbon::now();
        $months = [];

        // Build historical series: headcount at end of each of the last N months.
        for ($i = self::HISTORICAL_MONTHS - 1; $i >= 0; $i--) {
            $endOfMonth = $now->copy()->subMonthsNoOverflow($i)->endOfMonth();
            $months[] = [
                'year'  => (int) $endOfMonth->year,
                'month' => (int) $endOfMonth->month,
                'date'  => $endOfMonth->toDateString(),
            ];
        }

        $historical = [];
        foreach ($months as $m) {
            $count = (int) DB::table('employees')
                ->where('status', 'active')
                ->where('date_hired', '<=', $m['date'])
                ->where(function ($q) use ($m) {
                    $q->whereNull('deleted_at')
                      ->orWhere('deleted_at', '>', $m['date']);
                })
                ->count();

            $historical[] = [
                'year'  => $m['year'],
                'month' => $m['month'],
                'value' => $count,
            ];
        }

        // Apply moving average for forecast.
        $values      = array_map(fn ($r) => $r['value'], $historical);
        $avg         = count($values) > 0 ? array_sum($values) / count($values) : 0;
        $trend       = $this->computeTrend($values);
        $seasonality = $this->computeSeasonality($values, $historical);

        $forecast = [];
        for ($i = 1; $i <= self::FORECAST_MONTHS; $i++) {
            $future = $now->copy()->addMonthsNoOverflow($i);
            $base   = round($avg);
            // Apply seasonal adjustment if available.
            $adj    = $seasonality[$future->month] ?? 0;
            $val    = max(0, (int) round($base + $adj));

            $forecast[] = [
                'year'       => (int) $future->year,
                'month'      => (int) $future->month,
                'value'      => $val,
                'confidence' => $this->confidenceFromForecast($values, $base),
            ];
        }

        $currentHeadcount = $historical[count($historical) - 1]['value'] ?? 0;
        $avgForecast = count($forecast) > 0
            ? (int) round(array_sum(array_column($forecast, 'value')) / count($forecast))
            : $currentHeadcount;

        return [
            'historical' => $historical,
            'forecast'   => $forecast,
            'trend'      => $trend,
            'kpi'        => [
                'label' => 'Projected Headcount',
                'value' => (string) $avgForecast,
                'unit'  => 'count',
                'trend' => $trend,
            ],
        ];
    }

    /* ───────────────────────────────────────────────────────────────
     * 2. Finance — Revenue forecast
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Return monthly revenue (posted JEs, revenue-type accounts) for last
     * 12 months + forecast for next 6 months.
     *
     * @return array{historical: array, forecast: array, trend: string, kpi: array}
     */
    public function revenueForecast(): array
    {
        if (! Schema::hasTable('journal_entry_lines') || ! Schema::hasTable('accounts') || ! Schema::hasTable('journal_entries')) {
            return $this->emptyResponse();
        }

        $now    = Carbon::now();
        $lookback = 12; // Revenue benefits from more data (seasonality).
        $months = [];

        for ($i = $lookback - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $monthEnd   = $now->copy()->subMonthsNoOverflow($i)->endOfMonth();
            $months[] = [
                'year'  => (int) $monthStart->year,
                'month' => (int) $monthStart->month,
                'start' => $monthStart->toDateString(),
                'end'   => $monthEnd->toDateString(),
            ];
        }

        $historical = [];
        foreach ($months as $m) {
            $revenue = (float) DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jel.account_id')
                ->where('je.status', 'posted')
                ->where('a.type', 'revenue')
                ->whereBetween('je.date', [$m['start'], $m['end']])
                ->selectRaw('COALESCE(SUM(jel.credit) - SUM(jel.debit), 0) as rev')
                ->value('rev');

            $historical[] = [
                'year'  => $m['year'],
                'month' => $m['month'],
                'value' => round($revenue, 2),
            ];
        }

        $values = array_map(fn ($r) => $r['value'], $historical);
        $avg    = count($values) > 0 ? array_sum($values) / count($values) : 0;
        $trend  = $this->computeTrend($values);
        $seasonality = $this->computeSeasonality($values, $historical);

        $forecast = [];
        for ($i = 1; $i <= self::FORECAST_MONTHS; $i++) {
            $future = $now->copy()->addMonthsNoOverflow($i);
            $adj    = $seasonality[$future->month] ?? 0;
            $val    = round(max(0, $avg + $adj), 2);

            $forecast[] = [
                'year'       => (int) $future->year,
                'month'      => (int) $future->month,
                'value'      => $val,
                'confidence' => $this->confidenceFromForecast($values, $avg),
            ];
        }

        $lastRevenue = $historical[count($historical) - 1]['value'] ?? 0;
        $avgForecast = round(array_sum(array_column($forecast, 'value')), 2);

        return [
            'historical' => $historical,
            'forecast'   => $forecast,
            'trend'      => $trend,
            'kpi'        => [
                'label' => 'Projected Revenue (6mo)',
                'value' => number_format($avgForecast, 2, '.', ''),
                'unit'  => 'PHP',
                'trend' => $trend,
            ],
        ];
    }

    /* ───────────────────────────────────────────────────────────────
     * 3. Quality — Defect rate forecast
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Return monthly defect rate (defect_count / total) for last 6 months
     * + forecast for next 6 months.
     *
     * Defect rate = defect_count / sample_size per inspection, averaged
     * across inspections in a month. Rate is expressed as a percentage (0–100).
     *
     * @return array{historical: array, forecast: array, trend: string, kpi: array}
     */
    public function defectRateForecast(): array
    {
        if (! Schema::hasTable('inspections')) {
            return $this->emptyResponse();
        }

        $now    = Carbon::now();
        $months = [];

        for ($i = self::HISTORICAL_MONTHS - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $monthEnd   = $now->copy()->subMonthsNoOverflow($i)->endOfMonth();
            $months[] = [
                'year'  => (int) $monthStart->year,
                'month' => (int) $monthStart->month,
                'start' => $monthStart->toDateTimeString(),
                'end'   => $monthEnd->toDateTimeString(),
            ];
        }

        $historical = [];
        foreach ($months as $m) {
            // We want inspections that have completed (result matters).
            $rows = DB::table('inspections')
                ->whereBetween('completed_at', [$m['start'], $m['end']])
                ->whereIn('status', ['passed', 'failed'])
                ->selectRaw('COALESCE(SUM(sample_size), 0) as total_sampled, COALESCE(SUM(defect_count), 0) as defects')
                ->first();

            $totalSampled = (int) ($rows->total_sampled ?? 0);
            $defects      = (int) ($rows->defects ?? 0);
            $rate         = $totalSampled > 0 ? round(($defects * 100.0) / $totalSampled, 2) : 0.0;

            $historical[] = [
                'year'     => $m['year'],
                'month'    => $m['month'],
                'value'    => $rate,
                'total'    => $totalSampled,
                'defects'  => $defects,
            ];
        }

        $values = array_map(fn ($r) => $r['value'], $historical);
        $avg    = count($values) > 0 ? array_sum($values) / count($values) : 0;
        $trend  = $this->computeTrend($values);

        $forecast = [];
        for ($i = 1; $i <= self::FORECAST_MONTHS; $i++) {
            $future = $now->copy()->addMonthsNoOverflow($i);
            $val    = round(max(0, min(100, $avg)), 2);

            $forecast[] = [
                'year'       => (int) $future->year,
                'month'      => (int) $future->month,
                'value'      => $val,
                'confidence' => $this->confidenceFromForecast($values, $avg),
            ];
        }

        $currentRate = $historical[count($historical) - 1]['value'] ?? 0;
        $avgForecast = round(array_sum(array_column($forecast, 'value')) / max(1, count($forecast)), 2);

        return [
            'historical' => $historical,
            'forecast'   => $forecast,
            'trend'      => $trend,
            'kpi'        => [
                'label' => 'Projected Defect Rate',
                'value' => number_format($avgForecast, 1, '.', '') . '%',
                'unit'  => 'pct',
                'trend' => $trend,
            ],
        ];
    }

    /* ───────────────────────────────────────────────────────────────
     * Shared helpers
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Compute trend direction: 'up', 'down', or 'stable'.
     * Uses linear regression slope normalized by the mean.
     */
    private function computeTrend(array $values): string
    {
        $n = count($values);
        if ($n < 3) return 'stable';

        $xMean = ($n - 1) / 2;
        $yMean = array_sum($values) / $n;

        $num = 0.0;
        $den = 0.0;
        foreach ($values as $i => $y) {
            $dx = $i - $xMean;
            $num += $dx * ($y - $yMean);
            $den += $dx * $dx;
        }

        if ($den === 0.0) return 'stable';
        $slope = $num / $den;
        $threshold = $yMean > 0 ? abs(0.03 * $yMean) : 0.5;

        if ($slope > $threshold) return 'up';
        if ($slope < -$threshold) return 'down';
        return 'stable';
    }

    /**
     * Compute seasonal adjustment per month (1–12).
     * Returns array keyed by month with adjustment values.
     */
    private function computeSeasonality(array $values, array $historical): array
    {
        $n = count($values);
        if ($n < 12) return [];

        $avg = array_sum($values) / $n;
        if ($avg <= 0) return [];

        $byMonth = [];
        foreach ($historical as $i => $h) {
            $month = (int) $h['month'];
            if (! isset($byMonth[$month])) {
                $byMonth[$month] = [];
            }
            $byMonth[$month][] = $values[$i];
        }

        $adjustments = [];
        foreach ($byMonth as $month => $vals) {
            $monthAvg = array_sum($vals) / count($vals);
            $adjustments[$month] = $monthAvg - $avg;
        }

        return $adjustments;
    }

    /**
     * Confidence level based on coefficient of variation (same as ForecastingService).
     */
    private function confidenceFromForecast(array $values, float $forecastValue): ?float
    {
        $n = count($values);
        if ($n < 2) return null;

        $mean = array_sum($values) / $n;
        if ($mean <= 0) return 50.0;

        $variance = 0.0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $stddev = sqrt($variance / $n);
        $cv     = $stddev / $mean;
        return max(0.0, min(100.0, 100.0 - (100.0 * $cv)));
    }

    /**
     * Empty response used when the schema is missing a required table.
     */
    private function emptyResponse(): array
    {
        return [
            'historical' => [],
            'forecast'   => [],
            'trend'      => 'stable',
            'kpi'        => [
                'label' => 'Forecast',
                'value' => '—',
                'unit'  => '—',
                'trend' => 'stable',
            ],
        ];
    }
}
