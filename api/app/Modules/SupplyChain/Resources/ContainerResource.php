<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContainerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'shipment_id'      => $this->whenLoaded('shipment', fn () => $this->shipment->hash_id),
            'container_number' => $this->container_number,
            'seal_number'      => $this->seal_number,
            'size'             => $this->size instanceof \BackedEnum ? $this->size->value : $this->size,
            'type'             => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'gross_weight_kg'  => $this->gross_weight_kg !== null ? (float) $this->gross_weight_kg : null,
            'net_weight_kg'    => $this->net_weight_kg !== null ? (float) $this->net_weight_kg : null,
            'volume_cbm'       => $this->volume_cbm !== null ? (float) $this->volume_cbm : null,
            'notes'            => $this->notes,
            'created_at'       => optional($this->created_at)?->toISOString(),
            'updated_at'       => optional($this->updated_at)?->toISOString(),
        ];
    }
}
