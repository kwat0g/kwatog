<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\SettingsService;
use App\Modules\Dashboard\Support\ForecastTrend;
use App\Modules\Forecasting\Support\ForecastConfidence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 3 — Cash-flow forecast (Task 6). Read-only: no transaction, no writes. Moving average over AR/AP, mirroring `ForecastingDashboardService::revenueForecast`. */
final class CashFlowForecastService
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array{historical: array, forecast: array, trend: string, kpi: array, months: array, confidence: float|null} */
    public function forecast(): array
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasTable('bills')) {
            return $this->empty();
        }
        $now = Carbon::now();
        $lookback = $this->settings->requiredInt('forecasting.default_history_months', 1, 36); $horizon = $this->settings->requiredInt('forecasting.default_horizon_months', 1, 12);
        $history = [];
        foreach (range($lookback - 1, 0) as $i) {
            $m = $now->copy()->subMonthsNoOverflow($i);
            $s = $m->copy()->startOfMonth()->toDateString(); $e = $m->copy()->endOfMonth()->toDateString();
            $in = round((float) DB::table('invoices')->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('date', [$s, $e])->sum('total_amount'), 2);
            $out = round((float) DB::table('bills')->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('date', [$s, $e])->sum('total_amount'), 2);
            $history[] = ['year' => (int) $m->year, 'month' => (int) $m->month, 'inflow' => $in, 'outflow' => $out, 'net' => round($in - $out, 2)];
        }
        if (array_sum(array_column($history, 'inflow')) + array_sum(array_column($history, 'outflow')) <= 0) {
            return $this->empty();
        }
        $avgIn = array_sum(array_column($history, 'inflow')) / count($history); $avgOut = array_sum(array_column($history, 'outflow')) / count($history);
        $trend = $this->trend(array_column($history, 'net')); $confidence = ForecastConfidence::fromSeries(array_column($history, 'net')); $months = [];
        foreach (range(1, $horizon) as $i) {
            $f = $now->copy()->addMonthsNoOverflow($i);
            $months[] = ['year' => (int) $f->year, 'month' => (int) $f->month, 'inflow' => round($avgIn, 2), 'outflow' => round($avgOut, 2), 'net' => round($avgIn - $avgOut, 2), 'confidence' => $confidence];
        }
        return [
            'historical' => array_map(fn ($h) => ['year' => $h['year'], 'month' => $h['month'], 'value' => $h['net']], $history),
            'forecast' => array_map(fn ($m) => ['year' => $m['year'], 'month' => $m['month'], 'value' => $m['net'], 'confidence' => $m['confidence']], $months),
            'trend' => $trend,
            'kpi' => ['label' => "Projected Net Cash Flow ({$horizon}mo)", 'value' => number_format(array_sum(array_column($months, 'net')), 2, '.', ''), 'unit' => $this->currency(), 'trend' => $trend],
            'months' => $months, 'confidence' => $confidence,
        ];
    }

    /** Linear-regression slope direction via the shared helper (same thresholds as `ForecastingDashboardService::computeTrend()`). */
    private function trend(array $values): string
    {
        return ForecastTrend::direction($values, $this->settings->requiredFloat('forecasting.trend_slope_ratio', 0, 1), $this->settings->requiredFloat('forecasting.trend_minimum_slope', 0, 1000000));
    }

    private function empty(): array
    {
        return ['historical' => [], 'forecast' => [], 'trend' => 'stable', 'kpi' => ['label' => 'Forecast', 'value' => '—', 'unit' => '', 'trend' => 'stable'], 'months' => [], 'confidence' => null];
    }

    private function currency(): string
    {
        $value = $this->settings->get('accounting.functional_currency_code');
        return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : '';
    }
}
