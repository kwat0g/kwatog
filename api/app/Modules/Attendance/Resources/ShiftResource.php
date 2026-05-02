<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'name'           => $this->name,
            'start_time'     => substr((string) $this->start_time, 0, 5),
            'end_time'       => substr((string) $this->end_time, 0, 5),
            'break_minutes'  => (int) $this->break_minutes,
            'is_night_shift' => (bool) $this->is_night_shift,
            'is_extended'    => (bool) $this->is_extended,
            'auto_ot_hours'  => $this->auto_ot_hours !== null ? (string) $this->auto_ot_hours : null,
            'is_active'      => (bool) $this->is_active,
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
