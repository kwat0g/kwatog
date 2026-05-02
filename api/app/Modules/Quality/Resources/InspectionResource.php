<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'inspection_number'  => $this->inspection_number,
            'stage'              => $this->stage instanceof \BackedEnum ? $this->stage->value : $this->stage,
            'status'             => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'entity_type'        => $this->entity_type instanceof \BackedEnum ? $this->entity_type->value : $this->entity_type,
            'entity_id'          => $this->entity_id, // raw int kept for FE polymorphic link assembly
            'batch_quantity'     => (int) $this->batch_quantity,
            'sample_size'        => (int) $this->sample_size,
            'aql_code'           => $this->aql_code,
            'accept_count'       => (int) $this->accept_count,
            'reject_count'       => (int) $this->reject_count,
            'defect_count'       => (int) $this->defect_count,
            'started_at'         => optional($this->started_at)?->toISOString(),
            'completed_at'       => optional($this->completed_at)?->toISOString(),
            'notes'              => $this->notes,
            'product'            => $this->whenLoaded('product', fn () => $this->product ? [
                'id'           => $this->product->hash_id,
                'part_number'  => $this->product->part_number,
                'name'         => $this->product->name,
            ] : null),
            'inspector'          => $this->whenLoaded('inspector', fn () => $this->inspector ? [
                'id'   => $this->inspector->hash_id,
                'name' => $this->inspector->name,
            ] : null),
            'spec'               => $this->whenLoaded('spec', fn () => $this->spec ? [
                'id'        => $this->spec->hash_id,
                'version'   => (int) $this->spec->version,
                'is_active' => (bool) $this->spec->is_active,
            ] : null),
            'measurements'       => $this->whenLoaded('measurements', fn () =>
                InspectionMeasurementResource::collection($this->measurements)->resolve()
            ),
            'created_at'         => optional($this->created_at)?->toISOString(),
            'updated_at'         => optional($this->updated_at)?->toISOString(),
        ];
    }
}
