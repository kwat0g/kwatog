<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InspectionMeasurementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'sample_index'    => (int) $this->sample_index,
            'parameter_name'  => $this->parameter_name,
            'parameter_type'  => $this->parameter_type instanceof \BackedEnum
                ? $this->parameter_type->value
                : $this->parameter_type,
            'unit_of_measure' => $this->unit_of_measure,
            'nominal_value'   => $this->nominal_value !== null ? (float) $this->nominal_value : null,
            'tolerance_min'   => $this->tolerance_min !== null ? (float) $this->tolerance_min : null,
            'tolerance_max'   => $this->tolerance_max !== null ? (float) $this->tolerance_max : null,
            'measured_value'  => $this->measured_value !== null ? (float) $this->measured_value : null,
            'is_critical'    => (bool) $this->is_critical,
            'is_pass'        => $this->is_pass === null ? null : (bool) $this->is_pass,
            'notes'          => $this->notes,
        ];
    }
}
