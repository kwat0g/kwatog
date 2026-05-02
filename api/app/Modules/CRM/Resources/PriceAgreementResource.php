<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceAgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'product'        => $this->whenLoaded('product', fn () => [
                'id'              => $this->product->hash_id,
                'part_number'     => $this->product->part_number,
                'name'            => $this->product->name,
                'unit_of_measure' => $this->product->unit_of_measure,
            ]),
            'customer'       => $this->whenLoaded('customer', fn () => [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ]),
            'price'          => (string) $this->price,
            'effective_from' => optional($this->effective_from)->toDateString(),
            'effective_to'   => optional($this->effective_to)->toDateString(),
            'is_currently_active' => (bool) $this->is_currently_active,
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
