<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Resources;

use App\Modules\ReturnManagement\Enums\DispositionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->hash_id,
            // These leaked the raw integer PKs of products / items straight to
            // the client, which the URL-obfuscation rule forbids and which the
            // SPA cannot use anyway (it addresses everything by hash_id).
            'product_id'        => $this->product_id ? \App\Common\Support\HashId::encode((int) $this->product_id) : null,
            'item_id'           => $this->item_id ? \App\Common\Support\HashId::encode((int) $this->item_id) : null,
            'quantity'          => (string) $this->quantity,
            'returned_quantity' => (string) $this->returned_quantity,
            'unit_price'        => (string) $this->unit_price,
            'total'             => (string) $this->total,
            'reason'            => $this->reason,
            'condition'         => $this->condition,
            'disposition'       => $this->disposition,
            'disposition_label' => DispositionType::tryFrom((string) $this->disposition)?->label(),
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
