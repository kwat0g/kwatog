<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpcAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'rule_code'       => $this->rule_code instanceof \BackedEnum ? $this->rule_code->value : $this->rule_code,
            'severity'        => $this->severity,
            'notes'           => $this->notes,
            'acknowledged_at' => optional($this->acknowledged_at)?->toISOString(),
            'resolved_at'     => optional($this->resolved_at)?->toISOString(),
            'chart'           => $this->whenLoaded('controlChart', fn () => $this->controlChart ? [
                'id'         => $this->controlChart->hash_id,
                'chart_type' => $this->controlChart->chart_type instanceof \BackedEnum
                    ? $this->controlChart->chart_type->value
                    : $this->controlChart->chart_type,
            ] : null),
            'data_point'      => $this->whenLoaded('dataPoint', fn () => $this->dataPoint ? [
                'id'              => $this->dataPoint->hash_id,
                'subgroup_number' => (int) $this->dataPoint->subgroup_number,
                'subgroup_mean'   => $this->dataPoint->subgroup_mean,
            ] : null),
            'acknowledged_by' => $this->whenLoaded('acknowledgedByUser', fn () => $this->acknowledgedByUser ? [
                'id'   => $this->acknowledgedByUser->hash_id,
                'name' => $this->acknowledgedByUser->name,
            ] : null),
            'created_at'      => optional($this->created_at)?->toISOString(),
        ];
    }
}
