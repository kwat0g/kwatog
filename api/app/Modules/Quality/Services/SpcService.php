<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\InspectionSpecItem;
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
 * IATF 16949 interpretation:
 *   Cpk ≥ 1.67 → new-product launch requirement
 *   Cpk ≥ 1.33 → ongoing production target
 *   Cpk  1.0–1.33 → marginal, requires action plan
 *   Cpk < 1.0  → not capable, escalate
 */
class SpcService
{
    private const MIN_SAMPLES = 5;

    /**
     * Compute Cp and Cpk for a set of measurements against bilateral spec limits.
     * Returns null if fewer than MIN_SAMPLES or sigma is effectively zero.
     *
     * @param  float[]  $measurements
     * @return array{cp:float,cpk:float,cpu:float,cpl:float,mean:float,std_dev:float,sample_count:int,usl:float,lsl:float}|null
     */
    public function compute(array $measurements, float $usl, float $lsl): ?array
    {
        $measurements = array_values(array_filter($measurements, fn ($v) => $v !== null && is_numeric($v)));
        $n = count($measurements);
        if ($n < self::MIN_SAMPLES) {
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
     * spec) are included. Items with fewer than MIN_SAMPLES measurements are
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
}
