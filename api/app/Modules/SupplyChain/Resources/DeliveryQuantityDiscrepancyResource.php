<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryQuantityDiscrepancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'status' => $this->status->value,
            'rationale' => $this->rationale,
            'lines' => array_map(static fn (array $line): array => [
                'delivery_item_id' => app('hashids')->encode((int) $line['delivery_item_id']),
                'received_quantity' => $line['received_quantity'],
            ], $this->lines),
            'reported_at' => $this->created_at?->toISOString(),
            'resolution_reason' => $this->resolution_reason,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'resolved_by' => $this->whenLoaded('resolver', fn () => $this->resolver?->name),
            'acknowledged_at' => $this->acknowledged_at?->toISOString(),
        ];
    }
}
