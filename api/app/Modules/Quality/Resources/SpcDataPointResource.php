<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpcDataPointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'subgroup_number'  => (int) $this->subgroup_number,
            'subgroup_mean'    => $this->subgroup_mean,
            'subgroup_range'   => $this->subgroup_range,
            'subgroup_std_dev' => $this->subgroup_std_dev,
            'individual_value' => $this->individual_value,
            'moving_range'     => $this->moving_range,
            'sample_values'    => $this->sample_values,
            'alerts'           => $this->alerts,
            'inspection_ids'   => $this->inspection_ids,
            'recorded_at'      => optional($this->recorded_at)?->toISOString(),
            'created_at'       => optional($this->created_at)?->toISOString(),
        ];
    }
}
