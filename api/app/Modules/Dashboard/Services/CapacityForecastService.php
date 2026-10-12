<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\SettingsService;
use App\Modules\Dashboard\Support\ForecastTrend;
use App\Modules\Forecasting\Support\ForecastConfidence;
use App\Modules\Production\Enums\WorkOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Phase 3 — Capacity forecast (Task 7). Read-only plant aggregate; shortcut: keep the 90% breach threshold fixed until policy owners need runtime changes. */
final class CapacityForecastService
{
    private const BREACH_UTIL_PCT = 90.0;

    public function __construct(private readonly SettingsService $settings, private readonly KpiSnapshotService $snapshots) {}

    /** @return array{historical: array, forecast: array, trend: string, kpi: array, months: array, breach: bool, confidence: float|null} */
    public function forecast(): array
    {
        if (! Schema::hasTable('machines') || ! Schema::hasTable('work_orders')) { return $this->empty(); }
        $daily = (float) DB::table('machines')->whereNull('deleted_at')->sum('available_hours_per_day');
        if ($daily <= 0) { return $this->empty(); }
        $horizon = $this->settings->requiredInt('forecasting.default_horizon_months', 1, 12); $derate = $this->oeeDerate();
        $open = DB::table('work_orders')->whereNotIn('status', [WorkOrderStatus::Completed->value, WorkOrderStatus::Closed->value, WorkOrderStatus::Cancelled->value])->get(['planned_start', 'planned_end']);
        $now = Carbon::now(); $months = [];
        foreach (range(1, $horizon) as $i) {
            $m = $now->copy()->addMonthsNoOverflow($i);
            $cap = round($daily * $m->daysInMonth * $derate, 2); $load = round($this->loadFor($open, $m->copy()->startOfMonth(), $m->copy()->endOfMonth()), 2);
            $months[] = ['year' => (int) $m->year, 'month' => (int) $m->month, 'load_hours' => $load, 'capacity_hours' => $cap, 'util_pct' => $cap > 0 ? round($load * 100 / $cap, 1) : 0.0];
        }
        $utils = array_column($months, 'util_pct'); $confidence = ForecastConfidence::fromSeries(array_column($months, 'load_hours'));
        $trend = ForecastTrend::direction($utils, $this->settings->requiredFloat('forecasting.trend_slope_ratio', 0, 1), $this->settings->requiredFloat('forecasting.trend_minimum_slope', 0, 1000000));
        foreach ($months as &$month) { $month['confidence'] = $confidence; } unset($month);
        return [
            'historical' => [], 'trend' => $trend,
            'forecast' => array_map(fn ($mo) => ['year' => $mo['year'], 'month' => $mo['month'], 'value' => $mo['util_pct'], 'confidence' => $mo['confidence']], $months),
            'kpi' => ['label' => "Projected Capacity Load ({$horizon}mo)", 'value' => number_format($utils === [] ? 0 : max($utils), 1, '.', ''), 'unit' => 'pct', 'trend' => $trend],
            'months' => $months, 'breach' => count(array_filter($months, fn ($mo) => $mo['util_pct'] > self::BREACH_UTIL_PCT)) > 0, 'confidence' => $confidence,
        ];
    }

    /** Open-order hours overlapping [start, end], spread evenly across each WO's own planned span. */
    private function loadFor(iterable $open, Carbon $start, Carbon $end): float
    {
        $load = 0.0;
        foreach ($open as $wo) {
            $s = Carbon::parse((string) $wo->planned_start); $e = Carbon::parse((string) $wo->planned_end);
            if ($e->lt($s)) { [$s, $e] = [$e, $s]; }
            $span = $s->copy()->startOfDay()->diffInDays($e->copy()->startOfDay()) + 1;
            $os = $s->greaterThan($start) ? $s : $start; $oe = $e->lessThan($end) ? $e : $end;
            if ($oe->lt($os)) { continue; }
            $load += (($e->timestamp - $s->timestamp) / 3600) * ($os->copy()->startOfDay()->diffInDays($oe->copy()->startOfDay()) + 1) / $span;
        }
        return $load;
    }

    /** Latest OEE snapshot as a capacity derate; unknown OEE derates nothing rather than guessing. */
    private function oeeDerate(): float
    {
        try {
            $last = array_last($this->snapshots->getTrend('oee', 3));
            $v = is_array($last) ? (float) ($last['value'] ?? 0) : 0.0;
            return $v > 0 ? min(1.0, $v / 100) : 1.0;
        } catch (Throwable) { return 1.0; }
    }

    private function empty(): array
    {
        return ['historical' => [], 'forecast' => [], 'trend' => 'stable', 'kpi' => ['label' => 'Forecast', 'value' => '—', 'unit' => '', 'trend' => 'stable'], 'months' => [], 'breach' => false, 'confidence' => null];
    }
}
