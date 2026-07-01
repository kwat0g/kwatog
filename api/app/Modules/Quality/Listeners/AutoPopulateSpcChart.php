<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Modules\Quality\Enums\SpcChartStatus;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\SpcControlChart;
use App\Modules\Quality\Services\SpcService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class AutoPopulateSpcChart implements ShouldQueue
{
    public function __construct(private readonly SpcService $spc) {}

    public function handle(object $event): void
    {
        try {
            $inspection = $event->inspection;
            if (!$inspection || !$inspection->product_id) {
                return;
            }

            $charts = SpcControlChart::where('product_id', $inspection->product_id)
                ->where('status', SpcChartStatus::Active)
                ->get();

            foreach ($charts as $chart) {
                $measurements = InspectionMeasurement::where('inspection_id', $inspection->id)
                    ->where('inspection_spec_item_id', $chart->spec_item_id)
                    ->whereNotNull('measured_value')
                    ->pluck('measured_value')
                    ->map(fn ($v) => (float) $v)
                    ->toArray();

                if (count($measurements) >= $chart->subgroup_size) {
                    $subgroup = array_slice($measurements, 0, $chart->subgroup_size);
                    $this->spc->recordDataPoint($chart, $subgroup, [$inspection->id]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SPC auto-populate failed: ' . $e->getMessage());
        }
    }
}
