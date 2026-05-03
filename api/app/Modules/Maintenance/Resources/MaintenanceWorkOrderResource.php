<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Resources;

use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceWorkOrder
 */
class MaintenanceWorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $target = $this->maintainable_type === MaintainableType::Machine
            ? Machine::find($this->maintainable_id)
            : Mold::find($this->maintainable_id);

        return [
            'id'                => $this->hash_id,
            'mwo_number'        => $this->mwo_number,
            'maintainable_type' => $this->maintainable_type instanceof \BackedEnum ? $this->maintainable_type->value : $this->maintainable_type,
            'maintainable'      => $target ? [
                'id'   => $target->hash_id,
                'code' => $target->machine_code ?? $target->mold_code ?? null,
                'name' => $target->name,
            ] : null,
            'schedule'          => $this->whenLoaded('schedule', fn () => $this->schedule ? [
                'id'             => $this->schedule->hash_id,
                'description'    => $this->schedule->description,
                'interval_type'  => $this->schedule->interval_type instanceof \BackedEnum ? $this->schedule->interval_type->value : $this->schedule->interval_type,
                'interval_value' => (int) $this->schedule->interval_value,
            ] : null),
            'type'              => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'priority'          => $this->priority instanceof \BackedEnum ? $this->priority->value : $this->priority,
            'description'       => $this->description,
            'status'            => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'started_at'        => optional($this->started_at)?->toISOString(),
            'completed_at'      => optional($this->completed_at)?->toISOString(),
            'downtime_minutes'  => (int) $this->downtime_minutes,
            'cost'              => (string) $this->cost,
            'remarks'           => $this->remarks,
            'assignee'          => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id'          => $this->assignee->hash_id,
                'employee_no' => $this->assignee->employee_no,
                'name'        => trim(($this->assignee->first_name ?? '').' '.($this->assignee->last_name ?? '')),
            ] : null),
            'creator'           => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'logs'              => $this->whenLoaded('logs', fn () => $this->logs->map(fn ($l) => [
                'id'          => $l->hash_id,
                'description' => $l->description,
                'logger'      => $l->logger ? ['id' => $l->logger->hash_id, 'name' => $l->logger->name] : null,
                'created_at'  => optional($l->created_at)?->toISOString(),
            ])),
            'spare_parts'       => $this->whenLoaded('spareParts', fn () => $this->spareParts->map(fn ($s) => [
                'id'         => $s->hash_id,
                'item'       => $s->item ? [
                    'id'   => $s->item->hash_id,
                    'code' => $s->item->code,
                    'name' => $s->item->name,
                    'unit' => $s->item->unit_of_measure,
                ] : null,
                'quantity'   => (string) $s->quantity,
                'unit_cost'  => (string) $s->unit_cost,
                'total_cost' => (string) $s->total_cost,
                'created_at' => optional($s->created_at)?->toISOString(),
            ])),
            'created_at'        => optional($this->created_at)?->toISOString(),
            'updated_at'        => optional($this->updated_at)?->toISOString(),
        ];
    }
}
