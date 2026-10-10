<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Modules\Inventory\Models\ItemUomConversion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ItemUomConversion
 */
class ItemUomConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'item_id' => app('hashids')->encode($this->item_id),
            'from_uom' => $this->whenLoaded('fromUom', fn () => new UomResource($this->fromUom)),
            'to_uom' => $this->whenLoaded('toUom', fn () => new UomResource($this->toUom)),
            'factor' => (string) $this->factor,
            'locked_by_history' => (bool) $this->getAttribute('locked_by_history'),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
