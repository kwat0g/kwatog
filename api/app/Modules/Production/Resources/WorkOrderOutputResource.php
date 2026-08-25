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
            'material_lineage' => $this->normalizeMaterialLineage($this->material_lineage),
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

    private function normalizeMaterialLineage(mixed $lineage): mixed
    {
        if (! is_array($lineage)) {
            return $lineage;
        }

        if (array_key_exists('authorized_by', $lineage)) {
            $lineage['authorized_by'] = $this->hashId($lineage['authorized_by']);
        }

        if (is_array($lineage['materials'] ?? null)) {
            $lineage['materials'] = array_map(function (array $material): array {
                if (array_key_exists('item_id', $material)) {
                    $material['item_id'] = $this->hashId($material['item_id']);
                }

                return $material;
            }, $lineage['materials']);
        }

        return $lineage;
    }

    private function hashId(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (is_string($id) && ! ctype_digit($id)) {
            return $id;
        }

        return app('hashids')->encode((int) $id);
    }
}
