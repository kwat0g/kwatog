<?php

declare(strict_types=1);

namespace App\Modules\Production\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderOutputResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->hash_id,
            // Sprint 6 audit §1.3: never expose raw integer FKs. Surface the
            // parent WO via its hash_id when eager-loaded; callers that need
            // the WO link already have it from the route context.
            'work_order'   => $this->whenLoaded('workOrder', fn () => $this->workOrder ? [
                'id'        => $this->workOrder->hash_id,
                'wo_number' => $this->workOrder->wo_number,
            ] : null),
            'recorded_at'  => optional($this->recorded_at)->toIso8601String(),
            'good_count'   => (int) $this->good_count,
            'reject_count' => (int) $this->reject_count,
            'total_count'  => (int) $this->total_count,
            'shift'        => $this->shift,
            'batch_code'   => $this->batch_code,
            'remarks'      => $this->remarks,
            'recorder'     => $this->whenLoaded('recorder', fn () => $this->recorder ? [
                'id'   => $this->recorder->hash_id,
                'name' => $this->recorder->name,
            ] : null),
            'defects'      => $this->whenLoaded('defects', fn () =>
                $this->defects->map(fn ($d) => [
                    'id'           => $d->hash_id,
                    'count'        => (int) $d->count,
                    'defect_type'  => $d->relationLoaded('defectType') && $d->defectType ? [
                        'id'   => $d->defectType->hash_id,
                        'code' => $d->defectType->code,
                        'name' => $d->defectType->name,
                    ] : null,
                ])
            ),
        ];
    }
}
