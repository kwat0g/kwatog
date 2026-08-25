<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'purchase_order_item_id' => $this->purchase_order_item_id ? app('hashids')->encode((int) $this->purchase_order_item_id) : null,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
                'quality_plan_ready' => (bool) ($this->item->has_active_quality_plan ?? false),
            ]),
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->hash_id,
                'code' => $this->location->code,
                'full_code' => $this->location->full_code,
            ]),
            'quantity_received' => (string) $this->quantity_received,
            'quantity_accepted' => (string) $this->quantity_accepted,
            'unit_cost' => (string) $this->unit_cost,
            'received_uom_code' => $this->received_uom_code,
            'lot_number' => $this->material_lot_number,
            'supplier_lot_reference' => $this->supplier_lot_reference,
            'expiry_date' => optional($this->expiry_date)->toDateString(),
            'moisture_percentage' => $this->moisture_percentage !== null ? (string) $this->moisture_percentage : null,
            'coa_document_path' => $this->coa_document_path,
            'coa_verified' => (bool) $this->coa_verified,
            'remarks' => $this->remarks,
        ];
    }
}
