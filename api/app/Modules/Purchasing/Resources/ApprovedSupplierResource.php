<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovedSupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'item'           => $this->whenLoaded('item', fn () => [
                'id'   => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
            ]),
            'vendor'         => $this->whenLoaded('vendor', fn () => [
                'id'   => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ]),
            'is_preferred'   => (bool) $this->is_preferred,
            'lead_time_days' => (int) $this->lead_time_days,
            'last_price'     => $this->last_price ? (string) $this->last_price : null,
            'last_price_at'  => optional($this->last_price_at)->toIso8601String(),
        ];
    }
}
