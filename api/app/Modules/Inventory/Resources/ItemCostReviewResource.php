<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemCostReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->hash_id, 'item' => ['id' => $this->item->hash_id, 'code' => $this->item->code, 'name' => $this->item->name, 'unit_of_measure' => $this->item->unit_of_measure],
            'receipt_number' => $this->receiptLine->grn->grn_number, 'previous_cost' => $this->previous_cost, 'proposed_cost' => $this->proposed_cost,
            'current_cost' => $this->item->standard_cost, 'version' => $this->version, 'history' => $this->history,
            'status' => $this->status->value, 'notes' => $this->notes, 'created_at' => $this->created_at?->toISOString()];
    }
}
