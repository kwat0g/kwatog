<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemQualityPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'version' => (int) $this->version,
            'stage' => $this->stage,
            'sampling_method' => $this->sampling_method,
            'fixed_sample_size' => $this->fixed_sample_size,
            'aql_level' => $this->aql_level,
            'parameters' => $this->parameters,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->hash_id, 'code' => $this->item->code, 'name' => $this->item->name,
            ] : null),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id' => $this->vendor->hash_id, 'name' => $this->vendor->name,
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->hash_id, 'name' => $this->creator->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
