<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Modules\Quality\Enums\NcrDisposition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REC-08 — Material Review Board record. Never exposes raw integer ids.
 */
class MaterialReviewRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->hash_id,
            'mrb_number'  => $this->mrb_number,
            'status'      => $this->status->value,
            'status_label'=> $this->status->label(),
            'disposition' => $this->disposition,
            'disposition_label' => NcrDisposition::tryFrom((string) $this->disposition)?->label(),
            'quantity'    => (string) $this->quantity,

            'item' => $this->whenLoaded('item', fn () => [
                'id'   => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ]),

            'ncr' => $this->whenLoaded('ncr', fn () => $this->ncr ? [
                'id'         => $this->ncr->hash_id,
                'ncr_number' => $this->ncr->ncr_number,
            ] : null),

            'inspection' => $this->whenLoaded('inspection', fn () => $this->inspection ? [
                'id' => $this->inspection->hash_id,
            ] : null),

            'source_location'     => $this->locationPayload('sourceLocation'),
            'quarantine_location' => $this->locationPayload('quarantineLocation'),
            'release_location'    => $this->locationPayload('releaseLocation'),

            'hold_movement_id'    => $this->whenLoaded('holdMovement', fn () => $this->holdMovement?->hash_id),
            'release_movement_id' => $this->whenLoaded('releaseMovement', fn () => $this->releaseMovement?->hash_id),

            'held_by'     => $this->whenLoaded('holder', fn () => $this->holder?->name),
            'held_at'     => optional($this->held_at)->toIso8601String(),
            'released_by' => $this->whenLoaded('releaser', fn () => $this->releaser?->name),
            'released_at' => optional($this->released_at)->toIso8601String(),

            'notes'      => $this->notes,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    private function locationPayload(string $relation): mixed
    {
        return $this->whenLoaded($relation, function () use ($relation) {
            $loc = $this->{$relation};
            return $loc ? [
                'id'        => $loc->hash_id,
                'code'      => $loc->code,
                'full_code' => $loc->full_code,
                'zone'      => $loc->zone?->name,
                'zone_type' => $loc->zone?->zone_type instanceof \App\Modules\Inventory\Enums\WarehouseZoneType
                    ? $loc->zone->zone_type->value
                    : $loc->zone?->zone_type,
            ] : null;
        });
    }
}
