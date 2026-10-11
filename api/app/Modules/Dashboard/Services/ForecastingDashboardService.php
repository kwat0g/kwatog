<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\SettingsService;
use App\Modules\Dashboard\Support\ForecastTrend;
use App\Modules\Forecasting\Services\ForecastingService;
use App\Modules\Forecasting\Services\StockOutProjectionService;
use App\Modules\Forecasting\Support\ForecastConfidence;
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
    public function __construct(
        private readonly SettingsService $settings,
        private readonly StockOutProjectionService $stockOut,
        private readonly ForecastingService $forecasting,
        private readonly CashFlowForecastService $cashFlow,
        private readonly CapacityForecastService $capacity,
        private readonly AttendanceForecastService $attendance,
    ) {}
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

        $now = Carbon::now();
        $historicalMonths = $this->historicalMonths();
        $forecastMonths = $this->forecastMonths();
        $months = [];

        // Build historical series: headcount at end of each of the last N months.
        for ($i = $historicalMonths - 1; $i >= 0; $i--) {
            $endOfMonth = $now->copy()->subMonthsNoOverflow($i)->endOfMonth();
            $months[] = [
                'year' => (int) $endOfMonth->year,
                'month' => (int) $endOfMonth->month,
                'date' => $endOfMonth->toDateString(),
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
                'year' => $m['year'],
                'month' => $m['month'],
                'value' => $count,
            ];
        }

        // Apply moving average for forecast.
        $values = array_map(fn ($r) => $r['value'], $historical);
        $avg = count($values) > 0 ? array_sum($values) / count($values) : 0;
        $trend = $this->computeTrend($values);
        $seasonality = $this->computeSeasonality($values, $historical);

        $forecast = [];
        for ($i = 1; $i <= $forecastMonths; $i++) {
            $future = $now->copy()->addMonthsNoOverflow($i);
            $base = round($avg);
            // Apply seasonal adjustment if available.
            $adj = $seasonality[$future->month] ?? 0;
            $val = max(0, (int) round($base + $adj));

            $forecast[] = [
                'year' => (int) $future->year,
                'month' => (int) $future->month,
                'value' => $val,
                'confidence' => ForecastConfidence::fromSeries($values),
            ];
        }

        $currentHeadcount = $historical[count($historical) - 1]['value'] ?? 0;
        $avgForecast = count($forecast) > 0
            ? (int) round(array_sum(array_column($forecast, 'value')) / count($forecast))
            : $currentHeadcount;

        return [
            'historical' => $historical,
            'forecast' => $forecast,
            'trend' => $trend,
            'kpi' => [
                'label' => 'Projected Headcount',
                'value' => (string) $avgForecast,
                'unit' => 'count',
                'trend' => $trend,
            ],
        ];
    }

    /* ───────────────────────────────────────────────────────────────
     * 2. Finance — Revenue forecast
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Return monthly revenue (posted JEs, revenue-type accounts) for last
     * Configured history window + forecast horizon.
     *
     * @return array{historical: array, forecast: array, trend: string, kpi: array}
     */
    public function revenueForecast(): array
    {
        if (! Schema::hasTable('journal_entry_lines') || ! Schema::hasTable('accounts') || ! Schema::hasTable('journal_entries')) {
            return $this->emptyResponse();
        }

        $now = Carbon::now();
        $lookback = $this->historicalMonths();
        $forecastMonths = $this->forecastMonths();
        $months = [];

        for ($i = $lookback - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $monthEnd = $now->copy()->subMonthsNoOverflow($i)->endOfMonth();
            $months[] = [
                'year' => (int) $monthStart->year,
                'month' => (int) $monthStart->month,
                'start' => $monthStart->toDateString(),
                'end' => $monthEnd->toDateString(),
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
                'year' => $m['year'],
                'month' => $m['month'],
                'value' => round($revenue, 2),
            ];
        }

        $values = array_map(fn ($r) => $r['value'], $historical);
        $avg = count($values) > 0 ? array_sum($values) / count($values) : 0;
        $trend = $this->computeTrend($values);
        $seasonality = $this->computeSeasonality($values, $historical);

        $forecast = [];
        for ($i = 1; $i <= $forecastMonths; $i++) {
            $future = $now->copy()->addMonthsNoOverflow($i);
            $adj = $seasonality[$future->month] ?? 0;
            $val = round(max(0, $avg + $adj), 2);

            $forecast[] = [
                'year' => (int) $future->year,
                'month' => (int) $future->month,
                'value' => $val,
                'confidence' => ForecastConfidence::fromSeries($values),
            ];
        }

        $avgForecast = round(array_sum(array_column($forecast, 'value')), 2);

        return [
            'historical' => $historical,
            'forecast' => $forecast,
            'trend' => $trend,
            'kpi' => [
                'label' => "Projected Revenue ({$forecastMonths}mo)",
                'value' => number_format($avgForecast, 2, '.', ''),
                'unit' => $this->functionalCurrency(),
                'trend' => $trend,
            ],
        ];
    }

    /* ───────────────────────────────────────────────────────────────
     * 3. Quality — Defect rate forecast
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Return monthly defect rate (defect_count / total) for the configured
     * history window plus the configured forecast horizon.
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

        $now = Carbon::now();
        $historicalMonths = $this->historicalMonths();
        $forecastMonths = $this->forecastMonths();
        $months = [];

        for ($i = $historicalMonths - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $monthEnd = $now->copy()->subMonthsNoOverflow($i)->endOfMonth();
            $months[] = [
                'year' => (int) $monthStart->year,
                'month' => (int) $monthStart->month,
                'start' => $monthStart->toDateTimeString(),
                'end' => $monthEnd->toDateTimeString(),
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
            $defects = (int) ($rows->defects ?? 0);
            // A month with no completed inspections has no defect-rate
            // observation. Do not turn that absence into a perfect 0% month;
            // omit it from the evidence series instead.
            if ($totalSampled === 0) {
                continue;
            }
            $rate = round(($defects * 100.0) / $totalSampled, 2);

            $historical[] = [
                'year' => $m['year'],
                'month' => $m['month'],
                'value' => $rate,
                'total' => $totalSampled,
                'defects' => $defects,
            ];
        }

        if ($historical === []) {
            return $this->emptyResponse();
        }

        $values = array_map(fn ($r) => $r['value'], $historical);
        $avg = count($values) > 0 ? array_sum($values) / count($values) : 0;
        $trend = $this->computeTrend($values);

        $forecast = [];
        for ($i = 1; $i <= $forecastMonths; $i++) {
            $future = $now->copy()->addMonthsNoOverflow($i);
            $val = round(max(0, min(100, $avg)), 2);

            $forecast[] = [
                'year' => (int) $future->year,
                'month' => (int) $future->month,
                'value' => $val,
                'confidence' => ForecastConfidence::fromSeries($values),
            ];
        }

        $avgForecast = round(array_sum(array_column($forecast, 'value')) / max(1, count($forecast)), 2);

        return [
            'historical' => $historical,
            'forecast' => $forecast,
            'trend' => $trend,
            'kpi' => [
                'label' => 'Projected Defect Rate',
                'value' => number_format($avgForecast, 1, '.', ''),
                'unit' => 'pct',
                'trend' => $trend,
            ],
        ];
    }

    /* ───────────────────────────────────────────────────────────────
     * 4. Purchasing — Supplier-risk composition (Task 5)
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Supplier risk as a COMPOSITION, not a new engine: stock-out rows from
     * StockOutProjectionService plus the demand MAPE/bias from
     * ForecastingService. Read-only by construction — a fixed key set with no
     * override or mutate fields (override + MRP toggle stay on /forecasting).
     *
     * @return array{at_risk_count: int, critical_count: int, high_count: int, horizon_days: int, mape: float|null, bias: float|null, periods_evaluated: int, top_items: array<int, array{item_id: string, code: string, name: string, days_until_stockout: int|null, risk: string}>}
     */
    public function supplierRisk(?int $horizonDays = null): array
    {
        $rows = $this->stockOut->projectAll($horizonDays);
        $atRisk = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['risk'] !== 'ok',
        ));
        $accuracy = $this->forecasting->accuracy(Carbon::now()->year);

        $countRisk = static fn (string $risk): int => count(array_filter(
            $atRisk,
            static fn (array $row): bool => $row['risk'] === $risk,
        ));

        return [
            'at_risk_count' => count($atRisk),
            'critical_count' => $countRisk('critical'),
            'high_count' => $countRisk('high'),
            'horizon_days' => $horizonDays ?? $this->settings->requiredInt('inventory.stockout.default_horizon_days', 1),
            'mape' => $accuracy['mape'],
            'bias' => $accuracy['bias'],
            'periods_evaluated' => $accuracy['periods_evaluated'],
            // projectAll sorts worst-first, so the first five are the five
            // most urgent. Item ids are HashIDs, safe to link from the SPA.
            'top_items' => array_map(
                static fn (array $row): array => [
                    'item_id' => $row['item_id'],
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'days_until_stockout' => $row['days_until_stockout'],
                    'risk' => $row['risk'],
                ],
                array_slice($atRisk, 0, 5),
            ),
        ];
    }

    /* ───────────────────────────────────────────────────────────────
     * 5. Finance — Cash-flow forecast (Task 6)
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Thin delegate: the panel and the widget share the one
     * CashFlowForecastService engine (ponytail: one implementation).
     *
     * @return array{historical: array, forecast: array, trend: string, kpi: array, months: array, confidence: float|null}
     */
    public function cashFlowForecast(): array
    {
        return $this->cashFlow->forecast();
    }

    /* ───────────────────────────────────────────────────────────────
     * 6. PPC / Plant — Capacity forecast (Task 7)
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Thin delegate: the panels and the widget share the one
     * CapacityForecastService engine (ponytail: one implementation).
     *
     * @return array{historical: array, forecast: array, trend: string, kpi: array, months: array, breach: bool, confidence: float|null}
     */
    public function capacityForecast(): array
    {
        return $this->capacity->forecast();
    }

    /* ───────────────────────────────────────────────────────────────
     * 7. HR — Attendance forecast (Task 8)
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Thin delegate: the panel and the widget share the one
     * AttendanceForecastService engine (ponytail: one implementation).
     *
     * @return array{historical: array, forecast: array, trend: string, kpi: array, months: array, risk: string, confidence: float|null}
     */
    public function attendanceForecast(): array
    {
        return $this->attendance->forecast();
    }

    /* ───────────────────────────────────────────────────────────────
     * Shared helpers
     * ─────────────────────────────────────────────────────────────── */

    /**
     * Compute trend direction: 'up', 'down', or 'stable'.
     * Delegates to the shared helper so every forecast agrees on the math.
     */
    private function computeTrend(array $values): string
    {
        $slopeRatio = $this->settings->requiredFloat('forecasting.trend_slope_ratio', 0, 1);
        $minimumSlope = $this->settings->requiredFloat('forecasting.trend_minimum_slope', 0, 1000000);

        return ForecastTrend::direction($values, $slopeRatio, $minimumSlope);
    }

    /**
     * Compute seasonal adjustment per month (1–12).
     * Returns array keyed by month with adjustment values.
     */
    private function computeSeasonality(array $values, array $historical): array
    {
        $n = count($values);
        if ($n < $this->historicalMonths()) {
            return [];
        }

        $avg = array_sum($values) / $n;
        if ($avg <= 0) {
            return [];
        }

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

    private function historicalMonths(): int
    {
        return $this->settings->requiredInt('forecasting.default_history_months', 1, 36);
    }

    private function forecastMonths(): int
    {
        return $this->settings->requiredInt('forecasting.default_horizon_months', 1, 12);
    }

    private function functionalCurrency(): string
    {
        $value = $this->settings->get('accounting.functional_currency_code');
        return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : '';
    }

    /**
     * Empty response used when the schema is missing a required table.
     */
    private function emptyResponse(): array
    {
        return [
            'historical' => [],
            'forecast' => [],
            'trend' => 'stable',
            'kpi' => [
                'label' => 'Forecast',
                'value' => '—',
                'unit' => '',
                'trend' => 'stable',
            ],
        ];
    }
}
