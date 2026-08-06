<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\SettingsService;
use App\Modules\Quality\Enums\SpcAlertRule;
use App\Modules\Quality\Enums\SpcChartStatus;
use App\Modules\Quality\Enums\SpcChartType;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\SpcAlert;
use App\Modules\Quality\Models\SpcControlChart;
use App\Modules\Quality\Models\SpcDataPoint;
use Illuminate\Support\Facades\DB;

/**
 * Statistical Process Control (SPC) capability indices.
 *
 * Computes Cp and Cpk from measurement samples stored in
 * inspection_measurements.measured_value, grouped by inspection_spec_item_id.
 * Pure math — no side effects — fully unit-testable without the DB.
 *
 * Cp  = (USL - LSL) / (6σ)          — total spread capability
 * Cpu = (USL - x̄)  / (3σ)           — upper one-sided capability
 * Cpl = (x̄  - LSL) / (3σ)           — lower one-sided capability
 * Cpk = min(Cpu, Cpl)               — worst-case capability (process centring)
 *
 * Capability interpretation is supplied by the live quality SPC policy settings.
 */
class SpcService
{
    public function __construct(private readonly ?SettingsService $settings = null) {}

    private function settings(): SettingsService
    {
        return $this->settings ?? app(SettingsService::class);
    }

    private function minimumCapabilitySamples(): int
    {
        // Pure unit callers may construct this mathematical service without
        // the application container; production paths always receive settings
        // through dependency injection.
        return $this->settings === null
            ? 5
            : $this->settings()->requiredInt('quality.spc.minimum_capability_samples', 2, 1000);
    }

    /** @return array{launch: float, ongoing: float, action: float, minimum_samples: int} */
    public function capabilityThresholds(): array
    {
        $launch = $this->settings()->requiredFloat('quality.spc.cpk_launch_threshold', 0, 10);
        $ongoing = $this->settings()->requiredFloat('quality.spc.cpk_ongoing_threshold', 0, 10);
        $action = $this->settings()->requiredFloat('quality.spc.cpk_action_threshold', 0, 10);
        if (! ($launch > $ongoing && $ongoing > $action)) {
            throw new \App\Common\Exceptions\BusinessRuleException('SPC Cpk thresholds must be strictly descending.');
        }
        return [
            'launch' => $launch,
            'ongoing' => $ongoing,
            'action' => $action,
            'minimum_samples' => $this->minimumCapabilitySamples(),
        ];
    }

    /**
     * Compute Cp and Cpk for a set of measurements against bilateral spec limits.
     * Returns null if fewer than the configured minimum sample count or sigma is effectively zero.
     *
     * @param  float[]  $measurements
     * @return array{cp:float,cpk:float,cpu:float,cpl:float,mean:float,std_dev:float,sample_count:int,usl:float,lsl:float}|null
     */
    public function compute(array $measurements, float $usl, float $lsl): ?array
    {
        $measurements = array_values(array_filter($measurements, fn ($v) => $v !== null && is_numeric($v)));
        $n = count($measurements);
        if ($n < $this->minimumCapabilitySamples()) {
            return null;
        }

        $mean     = array_sum($measurements) / $n;
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $measurements)) / ($n - 1);
        $sigma    = sqrt($variance);
        if ($sigma < 1e-10) {
            $sigma = 1e-10;
        }

        $cp  = ($usl - $lsl) / (6 * $sigma);
        $cpu = ($usl - $mean) / (3 * $sigma);
        $cpl = ($mean - $lsl) / (3 * $sigma);
        $cpk = min($cpu, $cpl);

        return [
            'cp'           => round($cp, 3),
            'cpk'          => round($cpk, 3),
            'cpu'          => round($cpu, 3),
            'cpl'          => round($cpl, 3),
            'mean'         => round($mean, 4),
            'std_dev'      => round($sigma, 4),
            'sample_count' => $n,
            'usl'          => $usl,
            'lsl'          => $lsl,
        ];
    }

    /**
     * Compute SPC for all items of an InspectionSpec across all completed inspections.
     *
     * Only items with both tolerance_min and tolerance_max populated (bilateral
     * spec) are included. Items with fewer than the configured minimum sample count are
     * silently skipped — the UI should convey "not enough data" where absent.
     *
     * @return array<string, array>  Keyed by inspection_spec_item hash_id
     */
    public function computeForSpec(int $inspectionSpecId): array
    {
        $items = InspectionSpecItem::where('inspection_spec_id', $inspectionSpecId)->get();
        $results = [];

        foreach ($items as $item) {
            if ($item->tolerance_min === null || $item->tolerance_max === null) {
                continue;
            }

            $measurements = DB::table('inspection_measurements')
                ->where('inspection_spec_item_id', $item->id)
                ->whereNotNull('measured_value')
                ->pluck('measured_value')
                ->map(fn ($v) => (float) $v)
                ->toArray();

            $spc = $this->compute($measurements, (float) $item->tolerance_max, (float) $item->tolerance_min);
            if ($spc !== null) {
                $results[$item->hash_id] = array_merge($spc, [
                    'parameter_name' => $item->parameter_name,
                    'unit'           => $item->unit_of_measure,
                ]);
            }
        }

        return $results;
    }

    /** @return array<int, array{A2: float, D3: float, D4: float, d2: float}> */
    private function xbarRConstants(): array
    {
        $constants = $this->settings()->get('quality.spc.xbar_r_constants');
        if (! is_array($constants) || $constants === []) {
            throw new \App\Common\Exceptions\BusinessRuleException('Required quality.spc.xbar_r_constants setting is missing or invalid.');
        }
        return $constants;
    }

    public function createChart(int $productId, int $specItemId, SpcChartType $type, int $subgroupSize = 5): SpcControlChart
    {
        return SpcControlChart::create([
            'product_id'    => $productId,
            'spec_item_id'  => $specItemId,
            'chart_type'    => $type,
            'subgroup_size' => $subgroupSize,
            'status'        => SpcChartStatus::Active,
        ]);
    }

    public function recordDataPoint(SpcControlChart $chart, array $measurements, array $inspectionIds = []): SpcDataPoint
    {
        // Point create + run-rule alerts + limit recalc are one logical
        // write; a mid-sequence failure must not leave a point without its
        // alerts or an un-recalculated chart (silent SPC corruption).
        return DB::transaction(fn () => $this->recordDataPointInner($chart, $measurements, $inspectionIds));
    }

    private function recordDataPointInner(SpcControlChart $chart, array $measurements, array $inspectionIds = []): SpcDataPoint
    {
        $nextSubgroup = ($chart->dataPoints()->max('subgroup_number') ?? 0) + 1;

        $values = array_values(array_filter($measurements, fn ($v) => $v !== null && is_numeric($v)));
        $values = array_map(fn ($v) => (float) $v, $values);

        $mean = count($values) > 0 ? array_sum($values) / count($values) : 0;
        $range = count($values) > 1 ? max($values) - min($values) : 0;
        $stdDev = count($values) > 1 ? $this->stdDev($values) : null;

        $point = SpcDataPoint::create([
            'control_chart_id' => $chart->id,
            'subgroup_number'  => $nextSubgroup,
            'subgroup_mean'    => round($mean, 6),
            'subgroup_range'   => round($range, 6),
            'subgroup_std_dev' => $stdDev !== null ? round($stdDev, 6) : null,
            'individual_value' => $chart->chart_type === SpcChartType::Imr ? $values[0] ?? null : null,
            'moving_range'     => null,
            'sample_values'    => $values,
            'recorded_at'      => now(),
            'inspection_ids'   => $inspectionIds,
        ]);

        if ($chart->chart_type === SpcChartType::Imr && $nextSubgroup > 1) {
            $prevPoint = $chart->dataPoints()->where('subgroup_number', $nextSubgroup - 1)->first();
            if ($prevPoint && $prevPoint->individual_value !== null && $point->individual_value !== null) {
                $point->update(['moving_range' => round(abs((float) $point->individual_value - (float) $prevPoint->individual_value), 6)]);
            }
        }

        if (!$chart->limits_locked && $chart->center_line !== null) {
            $violations = $this->evaluateRunRules($chart, $point);
            if (!empty($violations)) {
                $point->update(['alerts' => array_map(fn ($r) => $r->value, $violations)]);
                foreach ($violations as $rule) {
                    $severity = $rule === SpcAlertRule::BeyondThreeSigma ? 'critical' : 'warning';
                    SpcAlert::create([
                        'control_chart_id' => $chart->id,
                        'data_point_id'    => $point->id,
                        'rule_code'        => $rule,
                        'severity'         => $severity,
                    ]);
                }
                event(new \App\Modules\Quality\Events\SpcAlertTriggered($chart, $point, $violations));
            }
        }

        if (!$chart->limits_locked) {
            $totalPoints = $chart->dataPoints()->count();
            $start = $this->settings()->requiredInt('quality.spc.recalculate_after_points', 2, 1000);
            $interval = $this->settings()->requiredInt('quality.spc.recalculate_interval_points', 1, 1000);
            if ($totalPoints >= $start && ($totalPoints % $interval === 0 || $chart->center_line === null)) {
                $this->recalculateLimits($chart);
            }
        }

        return $point->fresh();
    }

    public function recalculateLimits(SpcControlChart $chart): void
    {
        $history = $this->settings()->requiredInt('quality.spc.display_history_points', 1, 1000);
        $minimum = $this->settings()->requiredInt('quality.spc.minimum_control_points', 2, 1000);
        $points = $chart->dataPoints()->orderBy('subgroup_number', 'desc')->limit($history)->get();
        if ($points->count() < $minimum) {
            return;
        }

        if ($chart->chart_type === SpcChartType::XbarR) {
            $constantsMap = $this->xbarRConstants();
            $constants = $constantsMap[$chart->subgroup_size] ?? $constantsMap[5] ?? reset($constantsMap);
            $grandMean = $points->avg('subgroup_mean');
            $avgRange = $points->avg('subgroup_range');

            $chart->update([
                'center_line'         => round((float) $grandMean, 6),
                'ucl'                 => round((float) $grandMean + $constants['A2'] * (float) $avgRange, 6),
                'lcl'                 => round((float) $grandMean - $constants['A2'] * (float) $avgRange, 6),
                'center_range'        => round((float) $avgRange, 6),
                'ucl_range'           => round($constants['D4'] * (float) $avgRange, 6),
                'lcl_range'           => round($constants['D3'] * (float) $avgRange, 6),
                'limits_sample_count' => $points->count(),
            ]);
        } elseif ($chart->chart_type === SpcChartType::Imr) {
            $values = $points->pluck('individual_value')->filter()->values();
            $mRanges = $points->pluck('moving_range')->filter()->values();
            $mean = $values->avg();
            $avgMR = $mRanges->avg();

            $chart->update([
                'center_line'         => round((float) $mean, 6),
                'ucl'                 => round((float) $mean + 2.66 * (float) $avgMR, 6),
                'lcl'                 => round((float) $mean - 2.66 * (float) $avgMR, 6),
                'center_range'        => round((float) $avgMR, 6),
                'ucl_range'           => round(3.267 * (float) $avgMR, 6),
                'lcl_range'           => 0,
                'limits_sample_count' => $values->count(),
            ]);
        }
    }

    /**
     * Evaluate Western Electric run rules for a new data point.
     *
     * @return SpcAlertRule[]
     */
    public function evaluateRunRules(SpcControlChart $chart, SpcDataPoint $point): array
    {
        if ($chart->center_line === null || $chart->ucl === null || $chart->lcl === null) {
            return [];
        }

        $recentPoints = $chart->dataPoints()
            ->where('subgroup_number', '<=', $point->subgroup_number)
            ->orderBy('subgroup_number', 'desc')
            ->limit(8)
            ->pluck('subgroup_mean')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        return $this->evaluateRunRulesFromValues(
            recentMeans: $recentPoints,
            centerLine: (float) $chart->center_line,
            ucl: (float) $chart->ucl,
            lcl: (float) $chart->lcl,
        );
    }

    /**
     * Pure function: evaluate run rules from values (unit-testable without DB).
     *
     * @param  float[]  $recentMeans  Most recent first (index 0 = current point)
     * @return SpcAlertRule[]
     */
    public function evaluateRunRulesFromValues(array $recentMeans, float $centerLine, float $ucl, float $lcl): array
    {
        $violations = [];
        $sigma = ($ucl - $centerLine) / 3;
        if ($sigma <= 0 || count($recentMeans) === 0) {
            return [];
        }

        $current = $recentMeans[0];

        // Rule 1: one point beyond 3σ (UCL/LCL)
        if ($current > $ucl || $current < $lcl) {
            $violations[] = SpcAlertRule::BeyondThreeSigma;
        }

        // Rule 2: 2 of 3 consecutive points beyond 2σ (same side)
        if (count($recentMeans) >= 3) {
            $twoSigmaUpper = $centerLine + 2 * $sigma;
            $twoSigmaLower = $centerLine - 2 * $sigma;

            $aboveCount = 0;
            $belowCount = 0;
            for ($i = 0; $i < 3; $i++) {
                if ($recentMeans[$i] > $twoSigmaUpper) $aboveCount++;
                if ($recentMeans[$i] < $twoSigmaLower) $belowCount++;
            }
            if ($aboveCount >= 2 || $belowCount >= 2) {
                $violations[] = SpcAlertRule::TwoOfThreeBeyondTwoSigma;
            }
        }

        // Rule 3: 4 of 5 consecutive points beyond 1σ (same side)
        if (count($recentMeans) >= 5) {
            $oneSigmaUpper = $centerLine + $sigma;
            $oneSigmaLower = $centerLine - $sigma;

            $aboveCount = 0;
            $belowCount = 0;
            for ($i = 0; $i < 5; $i++) {
                if ($recentMeans[$i] > $oneSigmaUpper) $aboveCount++;
                if ($recentMeans[$i] < $oneSigmaLower) $belowCount++;
            }
            if ($aboveCount >= 4 || $belowCount >= 4) {
                $violations[] = SpcAlertRule::FourOfFiveBeyondOneSigma;
            }
        }

        // Rule 4: 8 consecutive points on same side of center line
        if (count($recentMeans) >= 8) {
            $aboveCount = 0;
            $belowCount = 0;
            for ($i = 0; $i < 8; $i++) {
                if ($recentMeans[$i] > $centerLine) $aboveCount++;
                if ($recentMeans[$i] < $centerLine) $belowCount++;
            }
            if ($aboveCount === 8 || $belowCount === 8) {
                $violations[] = SpcAlertRule::EightSameSide;
            }
        }

        return $violations;
    }

    public function computeCapabilityStudy(int $productId, int $specItemId, int $sampleSize = 50): ?array
    {
        $specItem = InspectionSpecItem::find($specItemId);
        if (!$specItem || $specItem->tolerance_min === null || $specItem->tolerance_max === null) {
            return null;
        }

        $measurements = DB::table('inspection_measurements')
            ->where('inspection_spec_item_id', $specItemId)
            ->whereNotNull('measured_value')
            ->orderByDesc('id')
            ->limit($sampleSize)
            ->pluck('measured_value')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        $result = $this->compute($measurements, (float) $specItem->tolerance_max, (float) $specItem->tolerance_min);
        if ($result === null) {
            return null;
        }

        $result['histogram'] = $this->buildHistogram($measurements, (float) $specItem->tolerance_min, (float) $specItem->tolerance_max);

        return $result;
    }

    private function buildHistogram(array $values, float $lsl, float $usl, int $bins = 20): array
    {
        if (empty($values)) return [];

        $min = min(min($values), $lsl);
        $max = max(max($values), $usl);
        $range = $max - $min;
        if ($range <= 0) return [];

        $binWidth = $range / $bins;
        $histogram = array_fill(0, $bins, 0);
        $binEdges = [];

        for ($i = 0; $i <= $bins; $i++) {
            $binEdges[] = round($min + $i * $binWidth, 4);
        }

        foreach ($values as $v) {
            $idx = min((int) floor(($v - $min) / $binWidth), $bins - 1);
            $histogram[$idx]++;
        }

        return [
            'bins'      => $histogram,
            'bin_edges' => $binEdges,
            'lsl'       => $lsl,
            'usl'       => $usl,
        ];
    }

    private function stdDev(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0;
        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1);
        return sqrt($variance);
    }
}
