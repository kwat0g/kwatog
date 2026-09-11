<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single supplier response row. The `latest_response` block embedded in the
 * PO resources is a strict subset of this shape (no nested purchase_order /
 * resolver) — keep the two in sync when changing the contract.
 */
class PurchaseOrderResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->hash_id,
            'purchase_order_id'      => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder->hash_id),
            'type'                   => $this->response_type?->value ?? (string) $this->response_type,
            'status'                 => $this->status?->value ?? (string) $this->status,
            'proposed_delivery_date' => optional($this->proposed_delivery_date)->toDateString(),
            'notes'                  => $this->notes,
            'responded_at'           => optional($this->responded_at)->toIso8601String(),
            'resolved_at'            => optional($this->resolved_at)->toIso8601String(),
            'resolution_notes'       => $this->resolution_notes,
            'items'                  => $this->whenLoaded('items', fn () => $this->items->map(
                static fn ($item): array => [
                    'purchase_order_item_id' => $item->purchase_order_item_id !== null
                        ? app('hashids')->encode((int) $item->purchase_order_item_id)
                        : null,
                    'proposed_quantity'      => $item->proposed_quantity !== null ? (string) $item->proposed_quantity : null,
                    'proposed_unit_price'    => $item->proposed_unit_price !== null ? (string) $item->proposed_unit_price : null,
                    'reason'                 => $item->reason,
                ],
            )->values()->all()),
            'resolver'               => $this->whenLoaded('resolver', fn () => $this->resolver ? [
                'id'   => $this->resolver->hash_id,
                'name' => $this->resolver->name,
            ] : null),
        ];
    }
}
