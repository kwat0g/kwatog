<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\SettingsService;
use App\Modules\Dashboard\Support\ForecastTrend;
use App\Modules\Forecasting\Support\ForecastConfidence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 3 — Attendance forecast (Task 8). Read-only: no transaction, no writes. Plant-level aggregate only (ponytail: threshold consts, per-department split later if needed). */
final class AttendanceForecastService
{
    private const HIGH_LEAVE_PCT = 15.0;
    private const MED_LEAVE_PCT = 8.0;

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array{historical: array, forecast: array, trend: string, kpi: array, months: array, risk: string, confidence: float|null} */
    public function forecast(): array
    {
        if (! Schema::hasTable('attendances') || ! Schema::hasTable('leave_requests') || ! Schema::hasTable('employees')) { return $this->empty(); }
        $now = Carbon::now();
        $lookback = $this->settings->requiredInt('forecasting.default_history_months', 1, 36); $horizon = $this->settings->requiredInt('forecasting.default_horizon_months', 1, 12);
        $rates = [];
        foreach (range($lookback - 1, 0) as $i) {
            $m = $now->copy()->subMonthsNoOverflow($i); $s = $m->copy()->startOfMonth()->toDateString(); $e = $m->copy()->endOfMonth()->toDateString();
            $total = (int) DB::table('attendances')->whereBetween('date', [$s, $e])->count();
            if ($total === 0) { continue; }
            $absent = (int) DB::table('attendances')->whereBetween('date', [$s, $e])->where('status', 'absent')->count();
            $rates[] = round(100 * ($total - $absent) / $total, 1);
        }
        if ($rates === []) { return $this->empty(); }
        $headcount = (int) DB::table('employees')->where('status', 'active')->count();
        $expected = round(array_sum($rates) / count($rates), 1);
        $trend = ForecastTrend::direction($rates, $this->settings->requiredFloat('forecasting.trend_slope_ratio', 0, 1), $this->settings->requiredFloat('forecasting.trend_minimum_slope', 0, 1000000));
        $confidence = ForecastConfidence::fromSeries($rates); $months = [];
        foreach (range(1, $horizon) as $i) {
            $f = $now->copy()->addMonthsNoOverflow($i); $ms = $f->copy()->startOfMonth()->toDateString(); $me = $f->copy()->endOfMonth()->toDateString();
            $days = (float) DB::table('leave_requests')->where('status', 'approved')->where('start_date', '<=', $me)->where('end_date', '>=', $ms)->sum('days');
            $load = $headcount > 0 ? round($days * 100 / ($headcount * $f->daysInMonth), 1) : 0.0;
            $months[] = ['year' => (int) $f->year, 'month' => (int) $f->month, 'expected_rate' => $expected, 'leave_load' => $load, 'risk' => $this->risk($load), 'confidence' => $confidence];
        }
        return [
            'historical' => [], 'trend' => $trend,
            'forecast' => array_map(fn ($mo) => ['year' => $mo['year'], 'month' => $mo['month'], 'value' => $mo['expected_rate'], 'confidence' => $mo['confidence']], $months),
            'kpi' => ['label' => "Projected Attendance Rate ({$horizon}mo)", 'value' => number_format($expected, 1, '.', ''), 'unit' => 'pct', 'trend' => $trend],
            'months' => $months, 'risk' => $this->worst(array_column($months, 'risk')), 'confidence' => $confidence,
        ];
    }

    private function risk(float $load): string
    {
        return $load >= self::HIGH_LEAVE_PCT ? 'high' : ($load >= self::MED_LEAVE_PCT ? 'medium' : 'low');
    }

    private function worst(array $risks): string
    {
        return in_array('high', $risks, true) ? 'high' : (in_array('medium', $risks, true) ? 'medium' : 'low');
    }

    private function empty(): array
    {
        return ['historical' => [], 'forecast' => [], 'trend' => 'stable', 'kpi' => ['label' => 'Forecast', 'value' => '—', 'unit' => '', 'trend' => 'stable'], 'months' => [], 'risk' => 'low', 'confidence' => null];
    }
}
