<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'delivery_number' => $this->delivery_number,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'scheduled_date' => optional($this->scheduled_date)?->toDateString(),
            'delivered_at' => optional($this->delivered_at)?->toISOString(),
            'confirmed_at' => optional($this->confirmed_at)?->toISOString(),
            'receiver_name' => $this->receiver_name,
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id' => $this->salesOrder->hash_id,
                'so_number' => $this->salesOrder->so_number,
            ] : null),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->hash_id,
                'name' => $this->driver->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($item) {
                $product = $item->salesOrderItem?->product;

                return [
                    'id' => $item->hash_id,
                    'part_number' => $product?->part_number ?? '—',
                    'name' => $product?->name ?? '—',
                    'quantity_delivered' => (float) $item->quantity,
                ];
            })->all()),
            'proofs' => $this->whenLoaded('proofs', fn () => $this->proofs->map(fn ($proof) => [
                'id' => $proof->hash_id,
                'proof_type' => $proof->proof_type,
                'file_name' => $proof->file_name,
                'view_url' => "/api/v1/b2b/customer/deliveries/{$this->hash_id}/proofs/{$proof->hash_id}/view",
                'notes' => $proof->notes,
            ])->all()),
        ];
    }
}
