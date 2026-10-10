<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Modules\Purchasing\Resources\PurchaseOrderItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivablePurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'po_number' => $this->po_number,
            'status' => (string) $this->status?->value,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ]),
            // Parent set so each line can report its delivered (receipt) cost.
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items', fn () => $this->items->each(
                function ($item): void {
                    $item->setRelation('purchaseOrder', $this->resource);
                    $ordered = $item->item->convertToBase((string) $item->quantity, $item->unit);
                    $remaining = bcsub($ordered, (string) $item->quantity_accepted, 3);
                    $item->setAttribute('base_quantity_ordered', bcadd($ordered, '0', 3));
                    $item->setAttribute('base_quantity_remaining', bccomp($remaining, '0', 3) > 0 ? $remaining : '0.000');
                },
            ))),
        ];
    }
}
