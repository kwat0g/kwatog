<?php

declare(strict_types=1);

namespace App\Modules\Production\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'wo_number'           => $this->wo_number,
            'product'             => $this->whenLoaded('product', fn () => [
                'id' => $this->product->hash_id,
                'part_number' => $this->product->part_number,
                'name' => $this->product->name,
            ]),
            'sales_order'         => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id' => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
            ] : null),
            'machine'             => $this->whenLoaded('machine', fn () => $this->machine ? [
                'id' => $this->machine->hash_id,
                'machine_code' => $this->machine->machine_code,
                'name' => $this->machine->name,
            ] : null),
            'mold'                => $this->whenLoaded('mold', fn () => $this->mold ? [
                'id' => $this->mold->hash_id,
                'mold_code' => $this->mold->mold_code,
                'name' => $this->mold->name,
            ] : null),
            'quantity_target'     => (int) $this->quantity_target,
            'quantity_produced'   => (int) $this->quantity_produced,
            'quantity_good'       => (int) $this->quantity_good,
            'quantity_rejected'   => (int) $this->quantity_rejected,
            'progress_percentage' => (float) $this->progress_percentage,
            'scrap_rate'          => (string) $this->scrap_rate,
            'planned_start'       => optional($this->planned_start)->toIso8601String(),
            'planned_end'         => optional($this->planned_end)->toIso8601String(),
            'actual_start'        => optional($this->actual_start)->toIso8601String(),
            'actual_end'          => optional($this->actual_end)->toIso8601String(),
            'status'              => (string) $this->status?->value,
            'status_label'        => $this->status?->label(),
            'pause_reason'        => $this->pause_reason,
            'priority'            => (int) $this->priority,
            'creator'             => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->hash_id, 'name' => $this->creator->name,
            ] : null),
            'materials'           => $this->whenLoaded('materials', fn () =>
                $this->materials->map(fn ($m) => [
                    'id' => $m->hash_id,
                    'item' => $m->relationLoaded('item') && $m->item ? [
                        'id' => $m->item->hash_id, 'code' => $m->item->code,
                        'name' => $m->item->name, 'unit_of_measure' => $m->item->unit_of_measure,
                    ] : null,
                    'bom_quantity' => (string) $m->bom_quantity,
                    'actual_quantity_issued' => (string) $m->actual_quantity_issued,
                    'variance' => (string) $m->variance,
                ])
            ),
            'outputs'             => $this->whenLoaded('outputs', fn () =>
                $this->outputs->map(fn ($o) => [
                    'id' => $o->hash_id,
                    'recorded_at' => optional($o->recorded_at)->toIso8601String(),
                    'good_count' => (int) $o->good_count,
                    'reject_count' => (int) $o->reject_count,
                    'shift' => $o->shift,
                    'batch_code' => $o->batch_code,
                    'remarks' => $o->remarks,
                    'recorder' => $o->relationLoaded('recorder') && $o->recorder ? [
                        'id' => $o->recorder->hash_id, 'name' => $o->recorder->name,
                    ] : null,
                    'defects' => $o->relationLoaded('defects') ? $o->defects->map(fn ($d) => [
                        'id' => $d->hash_id,
                        'count' => (int) $d->count,
                        'defect_type' => $d->relationLoaded('defectType') && $d->defectType ? [
                            'id' => $d->defectType->hash_id,
                            'code' => $d->defectType->code,
                            'name' => $d->defectType->name,
                        ] : null,
                    ]) : [],
                ])
            ),
            'created_at'          => optional($this->created_at)->toIso8601String(),
            'updated_at'          => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
