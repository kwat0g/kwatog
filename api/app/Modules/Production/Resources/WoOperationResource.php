<?php

declare(strict_types=1);

namespace App\Modules\Production\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WoOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'work_order_id'   => $this->whenLoaded('workOrder', fn () => $this->workOrder->hash_id),
            'sequence'        => (int) $this->sequence,
            'operation_name'  => $this->operation_name,
            'status'          => $this->status->value,
            'status_label'    => $this->status->label(),
            'machine'         => $this->whenLoaded('machine', fn () => $this->machine ? [
                'id'           => $this->machine->hash_id,
                'machine_code' => $this->machine->machine_code,
                'name'         => $this->machine->name,
            ] : null),
            'mold'            => $this->whenLoaded('mold', fn () => $this->mold ? [
                'id'        => $this->mold->hash_id,
                'mold_code' => $this->mold->mold_code,
                'name'      => $this->mold->name,
            ] : null),
            'operator'        => $this->whenLoaded('operator', fn () => $this->operator ? [
                'id'         => $this->operator->hash_id,
                'first_name' => $this->operator->first_name,
                'last_name'  => $this->operator->last_name,
            ] : null),
            'planned_start'    => optional($this->planned_start)->toIso8601String(),
            'planned_end'      => optional($this->planned_end)->toIso8601String(),
            'actual_start'     => optional($this->actual_start)->toIso8601String(),
            'actual_end'       => optional($this->actual_end)->toIso8601String(),
            'setup_start'      => optional($this->setup_start)->toIso8601String(),
            'setup_end'        => optional($this->setup_end)->toIso8601String(),
            'qty_planned'      => $this->qty_planned,
            'qty_completed'    => $this->qty_completed,
            'qty_scrapped'     => $this->qty_scrapped,
            'scrap_reason'     => $this->scrap_reason,
            'downtime_minutes' => $this->downtime_minutes,
            'notes'            => $this->notes,
            'logs'             => $this->whenLoaded('logs', fn () =>
                $this->logs->map(fn ($log) => [
                    'id'              => $log->hash_id,
                    'operator_id'     => $log->operator ? $log->operator->hash_id : null,
                    'event_type'      => $log->event_type->value,
                    'event_label'     => $log->event_type->label(),
                    'qty_value'       => $log->qty_value,
                    'downtime_reason' => $log->downtime_reason,
                    'notes'           => $log->notes,
                    'recorded_at'     => optional($log->recorded_at)->toIso8601String(),
                ])
            ),
            'created_at'       => optional($this->created_at)->toIso8601String(),
            'updated_at'       => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
