<?php

declare(strict_types=1);

namespace App\Modules\MRP\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MachineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->hash_id,
            'machine_code'             => $this->machine_code,
            'name'                     => $this->name,
            'tonnage'                  => $this->tonnage,
            'machine_type'             => $this->machine_type,
            'operators_required'       => (string) $this->operators_required,
            'available_hours_per_day'  => (string) $this->available_hours_per_day,
            'status'                   => (string) $this->status?->value,
            'status_label'             => $this->status?->label(),
            'is_available_now'         => (bool) $this->is_available_now,
            'compatible_molds_count'   => (int) ($this->compatible_molds_count ?? 0),
            'compatible_molds'         => $this->whenLoaded('compatibleMolds', fn () =>
                $this->compatibleMolds->map(fn ($m) => [
                    'id' => $m->hash_id, 'mold_code' => $m->mold_code, 'name' => $m->name,
                ])
            ),
            'created_at'               => optional($this->created_at)->toIso8601String(),
            'updated_at'               => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
