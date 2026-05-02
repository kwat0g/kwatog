<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'grn_number'      => $this->grn_number,
            'received_date'   => optional($this->received_date)->toDateString(),
            'status'          => (string) $this->status?->value,
            'rejected_reason' => $this->rejected_reason,
            'remarks'         => $this->remarks,
            'accepted_at'     => optional($this->accepted_at)->toIso8601String(),
            'vendor'          => $this->whenLoaded('vendor', fn () => [
                'id'   => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ]),
            'purchase_order'  => $this->whenLoaded('purchaseOrder', fn () => [
                'id'        => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ]),
            'receiver'        => $this->whenLoaded('receiver', fn () => $this->receiver ? [
                'id'   => $this->receiver->hash_id,
                'name' => $this->receiver->name,
            ] : null),
            'acceptor'        => $this->whenLoaded('acceptor', fn () => $this->acceptor ? [
                'id'   => $this->acceptor->hash_id,
                'name' => $this->acceptor->name,
            ] : null),
            'items'           => GrnItemResource::collection($this->whenLoaded('items')),
            'created_at'      => optional($this->created_at)->toIso8601String(),
        ];
    }
}
