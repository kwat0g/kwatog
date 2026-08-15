<?php

declare(strict_types=1);

namespace App\Modules\Production\Resources;

use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
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
            'material_lineage' => $this->material_lineage,
            'production_receipt_handoff' => [
                'status' => $this->production_receipt_handoff_status instanceof ProductionReceiptHandoffStatus
                    ? $this->production_receipt_handoff_status->value
                    : (string) $this->production_receipt_handoff_status,
                'status_label' => ($handoff = $this->production_receipt_handoff_status instanceof ProductionReceiptHandoffStatus
                    ? $this->production_receipt_handoff_status
                    : ProductionReceiptHandoffStatus::tryFrom((string) $this->production_receipt_handoff_status))?->label(),
                'message' => $this->production_receipt_handoff_message,
                'at' => optional($this->production_receipt_handoff_at)->toIso8601String(),
                'movement_id' => $this->when($this->production_receipt_movement_id !== null, fn () =>
                    $this->relationLoaded('productionReceiptMovement') && $this->productionReceiptMovement
                        ? $this->productionReceiptMovement->hash_id
                        : null
                ),
            ],
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
