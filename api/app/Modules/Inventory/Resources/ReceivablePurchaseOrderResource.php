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
                fn ($item) => $item->setRelation('purchaseOrder', $this->resource),
            ))),
        ];
    }
}
