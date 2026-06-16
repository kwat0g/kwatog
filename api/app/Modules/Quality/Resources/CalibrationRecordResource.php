<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Quality\Models\CalibrationRecord
 */
class CalibrationRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->hash_id,
            'equipment_code'        => $this->equipment_code,
            'name'                  => $this->name,
            'location'              => $this->location,
            'last_calibration_date' => optional($this->last_calibration_date)->toDateString(),
            'next_calibration_date' => optional($this->next_calibration_date)->toDateString(),
            'frequency_days'        => $this->frequency_days,
            'status'                => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'responsible'           => $this->responsible,
            'remarks'               => $this->remarks,
            'created_at'            => optional($this->created_at)->toIso8601String(),
            'updated_at'            => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
