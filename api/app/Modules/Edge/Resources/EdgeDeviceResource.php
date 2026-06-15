<?php

declare(strict_types=1);

namespace App\Modules\Edge\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EdgeDeviceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->hash_id,
            'serial_number'  => $this->serial_number,
            'name'           => $this->name,
            'device_type'    => $this->device_type?->value,
            'location'       => $this->location,
            'machine_id'     => $this->machine_id ? app('hashids')->encode($this->machine_id) : null,
            'machine_code'   => $this->machine?->machine_code,
            'is_active'      => (bool) $this->is_active,
            'last_seen_at'   => optional($this->last_seen_at)->toIso8601String(),
            'notes'          => $this->notes,
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
