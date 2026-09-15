<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RfqAwardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id, 'awarded_quantity' => (string) $this->awarded_quantity,
            'awarded_unit_price' => (string) $this->awarded_unit_price, 'awarded_total_delivered_cost' => (string) $this->awarded_total_delivered_cost,
            'award_reason' => $this->award_reason, 'single_response_justification' => $this->single_response_justification,
            'status' => $this->status?->value ?? (string) $this->status, 'awarded_at' => optional($this->awarded_at)->toIso8601String(),
            'vendor' => $this->whenLoaded('vendor', fn () => ['id' => $this->vendor->hash_id, 'name' => $this->vendor->name]),
            'rfq_item' => $this->whenLoaded('rfqItem', fn () => ['id' => $this->rfqItem->hash_id, 'description' => $this->rfqItem->description]),
            'quote' => $this->whenLoaded('quote', fn () => ['id' => $this->quote->hash_id, 'version' => (int) $this->quote->version]),
        ];
    }
}
