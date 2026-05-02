<?php

declare(strict_types=1);

namespace App\Modules\MRP\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                           => $this->hash_id,
            'mold_code'                    => $this->mold_code,
            'name'                         => $this->name,
            'product'                      => $this->whenLoaded('product', fn () => $this->product ? [
                'id'              => $this->product->hash_id,
                'part_number'     => $this->product->part_number,
                'name'            => $this->product->name,
                'unit_of_measure' => $this->product->unit_of_measure,
            ] : null),
            'cavity_count'                 => (int) $this->cavity_count,
            'cycle_time_seconds'           => (int) $this->cycle_time_seconds,
            'output_rate_per_hour'         => (int) $this->output_rate_per_hour,
            'setup_time_minutes'           => (int) $this->setup_time_minutes,
            'current_shot_count'           => (int) $this->current_shot_count,
            'max_shots_before_maintenance' => (int) $this->max_shots_before_maintenance,
            'shot_percentage'              => (float) $this->shot_percentage,
            'nearing_limit'                => (bool) $this->nearing_limit,
            'lifetime_total_shots'         => (int) $this->lifetime_total_shots,
            'lifetime_max_shots'           => (int) $this->lifetime_max_shots,
            'status'                       => (string) $this->status?->value,
            'status_label'                 => $this->status?->label(),
            'location'                     => $this->location,
            'compatible_machines_count'    => (int) ($this->compatible_machines_count ?? 0),
            'compatible_machines'          => $this->whenLoaded('compatibleMachines', fn () =>
                $this->compatibleMachines->map(fn ($m) => [
                    'id' => $m->hash_id, 'machine_code' => $m->machine_code,
                    'name' => $m->name, 'tonnage' => $m->tonnage,
                ])
            ),
            'created_at'                   => optional($this->created_at)->toIso8601String(),
            'updated_at'                   => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
