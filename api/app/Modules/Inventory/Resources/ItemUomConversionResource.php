<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Inventory\Models\ItemUomConversion
 */
class ItemUomConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->hash_id,
            'item_id'   => app('hashids')->encode($this->item_id),
            'from_uom'  => $this->whenLoaded('fromUom', fn () => new UomResource($this->fromUom)),
            'to_uom'    => $this->whenLoaded('toUom', fn () => new UomResource($this->toUom)),
            'factor'    => (string) $this->factor,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
