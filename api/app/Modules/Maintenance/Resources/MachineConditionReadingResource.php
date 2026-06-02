<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Resources;

use App\Modules\Maintenance\Models\MachineConditionReading;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MachineConditionReading
 */
class MachineConditionReadingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->hash_id,
            'machine_id'   => $this->whenLoaded('machine', fn () => $this->machine->hash_id),
            'machine_code' => $this->whenLoaded('machine', fn () => $this->machine->machine_code),
            'machine_name' => $this->whenLoaded('machine', fn () => $this->machine->name),
            'metric'      => $this->metric,
            'value'       => (string) $this->value,
            'unit'        => $this->unit,
            'recorded_at' => optional($this->recorded_at)?->toISOString(),
            'source'      => $this->source,
            'notes'       => $this->notes,
            'created_at'  => optional($this->created_at)?->toISOString(),
        ];
    }
}
