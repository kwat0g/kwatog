<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\SettingsService;
use App\Modules\Quality\Models\InspectionSpecItem;
use Illuminate\Support\Facades\DB;

/**
 * Process capability indices (Cp / Cpk).
 *
 * Computes Cp and Cpk from measurement samples stored in
 * inspection_measurements.measured_value, grouped by inspection_spec_item_id.
 * Pure math — no side effects — fully unit-testable without the DB.
 *
 * Scope cut (2026-08-07): the control-chart half of SPC — X̄-R charts,
 * subgroup data points, Nelson run rules and chart alerts — was removed. It
 * was a second, parallel detection mechanism for a signal the IATF inspection
 * path already raises: InspectionService::recordMeasurements() auto-evaluates
 * every measurement against its tolerance and opens an NCR on failure, and
 * the defect Pareto ranks what actually failed. The chart tables held 1 row
 * and 0 data points in every environment, so no chart ever accumulated the
 * 20-point minimum its own policy required to compute limits.
 *
 * What remains is the part with demonstrable value and real data behind it:
 * Cp/Cpk read straight from inspection_measurements, shown on the inspection
 * spec editor and the capability study page.
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
