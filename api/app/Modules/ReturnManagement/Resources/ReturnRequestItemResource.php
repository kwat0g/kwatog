<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->hash_id,
            'product_id'        => $this->product_id,
            'item_id'           => $this->item_id,
            'quantity'          => (string) $this->quantity,
            'returned_quantity' => (string) $this->returned_quantity,
            'unit_price'        => (string) $this->unit_price,
            'total'             => (string) $this->total,
            'reason'            => $this->reason,
            'condition'         => $this->condition,
            'disposition'       => $this->disposition,
            'disposition_notes' => $this->disposition_notes,
            'ncr'               => $this->whenLoaded('ncr', fn () => $this->ncr ? [
                'id'         => $this->ncr->hash_id,
                'ncr_number' => $this->ncr->ncr_number,
            ] : null),
            'product'           => $this->whenLoaded('product', fn () => $this->product ? [
                'id'          => $this->product->hash_id,
                'part_number' => $this->product->part_number,
                'name'        => $this->product->name,
            ] : null),
            'item'              => $this->whenLoaded('item', fn () => $this->item ? [
                'id'   => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
            ] : null),
        ];
    }
}
