<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'purchase_request_item_id' => $this->purchase_request_item_id ? app('hashids')->encode((int) $this->purchase_request_item_id) : null,
            'rfq_award_id' => $this->rfq_award_id ? app('hashids')->encode((int) $this->rfq_award_id) : null,
            'supplier_quote_version_id' => $this->supplier_quote_version_id ? app('hashids')->encode((int) $this->supplier_quote_version_id) : null,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ]),
            'description' => $this->description,
            'quantity' => (string) $this->quantity,
            'unit' => $this->unit,
            'unit_price' => (string) $this->unit_price,
            'total' => (string) $this->total,
            'rfq_commercial' => $this->rfq_award_id !== null ? [
                'vat_amount' => $this->rfq_line_vat_amount !== null ? (string) $this->rfq_line_vat_amount : null,
                'freight_amount' => $this->rfq_line_freight_amount !== null ? (string) $this->rfq_line_freight_amount : null,
                'other_charges' => $this->rfq_line_other_charges !== null ? (string) $this->rfq_line_other_charges : null,
            ] : null,
            'quantity_received' => (string) $this->quantity_received,
            'quantity_accepted' => (string) $this->quantity_accepted,
            'quantity_remaining' => $this->quantity_remaining,
        ];
    }
}
