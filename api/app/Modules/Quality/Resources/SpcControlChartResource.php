<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpcControlChartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'chart_type'          => $this->chart_type instanceof \BackedEnum ? $this->chart_type->value : $this->chart_type,
            'status'              => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'subgroup_size'       => (int) $this->subgroup_size,
            'center_line'         => $this->center_line,
            'ucl'                 => $this->ucl,
            'lcl'                 => $this->lcl,
            'center_range'        => $this->center_range,
            'ucl_range'           => $this->ucl_range,
            'lcl_range'           => $this->lcl_range,
            'limits_locked'       => (bool) $this->limits_locked,
            'limits_sample_count' => $this->limits_sample_count ? (int) $this->limits_sample_count : null,
            'product'             => $this->whenLoaded('product', fn () => $this->product ? [
                'id'          => $this->product->hash_id,
                'part_number' => $this->product->part_number,
                'name'        => $this->product->name,
            ] : null),
            'spec_item'           => $this->whenLoaded('specItem', fn () => $this->specItem ? [
                'id'             => $this->specItem->hash_id,
                'parameter_name' => $this->specItem->parameter_name,
                'nominal_value'  => $this->specItem->nominal_value,
                'tolerance_min'  => $this->specItem->tolerance_min,
                'tolerance_max'  => $this->specItem->tolerance_max,
                'unit_of_measure' => $this->specItem->unit_of_measure,
            ] : null),
            'data_points'         => SpcDataPointResource::collection($this->whenLoaded('dataPoints')),
            'unresolved_alert_count' => $this->whenCounted('unresolvedAlerts', $this->unresolved_alerts_count),
            'created_at'          => optional($this->created_at)?->toISOString(),
            'updated_at'          => optional($this->updated_at)?->toISOString(),
        ];
    }
}
