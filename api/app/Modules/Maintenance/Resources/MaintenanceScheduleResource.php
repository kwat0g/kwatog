<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Resources;

use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceSchedule
 */
class MaintenanceScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $target = $this->maintainable_type === MaintainableType::Machine
            ? Machine::find($this->maintainable_id)
            : Mold::find($this->maintainable_id);

        return [
            'id'                => $this->hash_id,
            'maintainable_type' => $this->maintainable_type instanceof \BackedEnum ? $this->maintainable_type->value : $this->maintainable_type,
            'maintainable_id'   => $target?->hash_id,
            'maintainable'      => $target ? [
                'id'         => $target->hash_id,
                'code'       => $target->machine_code ?? $target->mold_code ?? null,
                'name'       => $target->name,
            ] : null,
            'schedule_type'     => $this->schedule_type,
            'description'       => $this->description,
            'interval_type'     => $this->interval_type instanceof \BackedEnum ? $this->interval_type->value : $this->interval_type,
            'interval_value'    => (int) $this->interval_value,
            'last_performed_at' => optional($this->last_performed_at)?->toISOString(),
            'next_due_at'       => optional($this->next_due_at)?->toISOString(),
            'is_active'         => (bool) $this->is_active,
            'work_orders_count' => $this->when(isset($this->work_orders_count), $this->work_orders_count),
            'created_at'        => optional($this->created_at)?->toISOString(),
            'updated_at'        => optional($this->updated_at)?->toISOString(),
        ];
    }
}
