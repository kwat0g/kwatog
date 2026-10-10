<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrDisposition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * REC-08 — Material Review Board record. Never exposes raw integer ids.
 */
class MaterialReviewRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'mrb_number' => $this->mrb_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'disposition' => $this->disposition,
            'disposition_label' => NcrDisposition::tryFrom((string) $this->disposition)?->label(),
            'quantity' => (string) $this->quantity,
            'release_inspected_quantity' => $this->release_inspected_quantity,
            'release_evidence_reference' => $this->release_evidence_reference,
            'concession_approval_reference' => $this->concession_approval_reference,
            'requires_concession_approval' => $this->resource->requiresConcessionApproval(),
            'actions' => $this->when(
                array_key_exists('release_capabilities', $this->resource->getAttributes()),
                fn () => $this->release_capabilities,
            ),

            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ]),

            'ncr' => $this->whenLoaded('ncr', fn () => $this->ncr ? [
                'id' => $this->ncr->hash_id,
                'ncr_number' => $this->ncr->ncr_number,
                'status' => $this->ncr->status instanceof \BackedEnum ? $this->ncr->status->value : $this->ncr->status,
                'status_label' => Str::headline((string) ($this->ncr->status instanceof \BackedEnum ? $this->ncr->status->value : $this->ncr->status)),
                'affected_quantity' => (int) $this->ncr->affected_quantity,
                // The MRB decision; the release form starts from it.
                'disposition' => $this->ncr->disposition instanceof \BackedEnum ? $this->ncr->disposition->value : $this->ncr->disposition,
                'disposition_label' => NcrDisposition::tryFrom((string) ($this->ncr->disposition instanceof \BackedEnum ? $this->ncr->disposition->value : $this->ncr->disposition))?->label(),
                'inspection' => $this->ncr->relationLoaded('inspection') && $this->ncr->inspection ? [
                    'id' => $this->ncr->inspection->hash_id,
                    'inspection_number' => $this->ncr->inspection->inspection_number,
                    'stage' => $this->ncr->inspection->stage instanceof \BackedEnum ? $this->ncr->inspection->stage->value : $this->ncr->inspection->stage,
                    'stage_label' => InspectionStage::tryFrom((string) ($this->ncr->inspection->stage instanceof \BackedEnum ? $this->ncr->inspection->stage->value : $this->ncr->inspection->stage))?->label(),
                    'status' => $this->ncr->inspection->status instanceof \BackedEnum ? $this->ncr->inspection->status->value : $this->ncr->inspection->status,
                    'status_label' => InspectionStatus::tryFrom((string) ($this->ncr->inspection->status instanceof \BackedEnum ? $this->ncr->inspection->status->value : $this->ncr->inspection->status))?->label(),
                ] : null,
            ] : null),

            'inspection' => $this->whenLoaded('inspection', fn () => $this->inspection ? [
                'id' => $this->inspection->hash_id,
                'inspection_number' => $this->inspection->inspection_number,
                'stage' => $this->inspection->stage instanceof \BackedEnum ? $this->inspection->stage->value : $this->inspection->stage,
                'stage_label' => InspectionStage::tryFrom((string) ($this->inspection->stage instanceof \BackedEnum ? $this->inspection->stage->value : $this->inspection->stage))?->label(),
                'status' => $this->inspection->status instanceof \BackedEnum ? $this->inspection->status->value : $this->inspection->status,
                'status_label' => InspectionStatus::tryFrom((string) ($this->inspection->status instanceof \BackedEnum ? $this->inspection->status->value : $this->inspection->status))?->label(),
                'batch_quantity' => (int) $this->inspection->batch_quantity,
            ] : null),

            'return_request' => $this->whenLoaded('returnRequestItem', fn () => $this->returnRequestItem?->relationLoaded('returnRequest')
                && $this->returnRequestItem->returnRequest ? [
                    'id' => $this->returnRequestItem->returnRequest->hash_id,
                    'rma_number' => $this->returnRequestItem->returnRequest->rma_number,
                    'line_id' => $this->returnRequestItem->hash_id,
                    'disposition' => $this->returnRequestItem->disposition,
                ] : null),

            'source_location' => $this->locationPayload('sourceLocation'),
            'quarantine_location' => $this->locationPayload('quarantineLocation'),
            'release_location' => $this->locationPayload('releaseLocation'),

            'hold_movement_id' => $this->whenLoaded('holdMovement', fn () => $this->holdMovement?->hash_id),
            'release_movement_id' => $this->whenLoaded('releaseMovement', fn () => $this->releaseMovement?->hash_id),

            'held_by_id' => $this->whenLoaded('holder', fn () => $this->holder?->hash_id),
            'held_by' => $this->whenLoaded('holder', fn () => $this->holder?->name),
            'held_at' => optional($this->held_at)->toIso8601String(),
            'released_by' => $this->whenLoaded('releaser', fn () => $this->releaser?->name),
            'released_at' => optional($this->released_at)->toIso8601String(),

            'notes' => $this->notes,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    private function locationPayload(string $relation): mixed
    {
        return $this->whenLoaded($relation, function () use ($relation) {
            $loc = $this->{$relation};

            return $loc ? [
                'id' => $loc->hash_id,
                'code' => $loc->code,
                'full_code' => $loc->full_code,
                'warehouse_id' => $loc->zone?->warehouse?->hash_id,
                'warehouse_code' => $loc->zone?->warehouse?->code,
                'warehouse_name' => $loc->zone?->warehouse?->name,
                'is_active' => (bool) $loc->is_active,
                'zone' => $loc->zone?->name,
                'zone_type' => $loc->zone?->zone_type instanceof WarehouseZoneType
                    ? $loc->zone->zone_type->value
                    : $loc->zone?->zone_type,
            ] : null;
        });
    }
}
