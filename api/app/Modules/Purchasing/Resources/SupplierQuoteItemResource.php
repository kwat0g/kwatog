<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierQuoteItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'request_for_quote_item_id' => app('hashids')->encode((int) $this->request_for_quote_item_id),
            'response_status' => $this->response_status?->value,
            'offered_quantity' => $this->offered_quantity !== null ? (string) $this->offered_quantity : null,
            'unit_price' => $this->unit_price !== null ? (string) $this->unit_price : null,
            'line_total' => (string) $this->line_total_delivered_cost,
            // Comparison only: the line plus its share of the quote's freight and VAT.
            'allocated_delivered_cost' => $this->allocated_delivered_cost ?? null,
            'unit_delivered_cost' => $this->unit_delivered_cost ?? null,
            // Comparison only: per-unit cost net of recoverable VAT.
            'unit_net_cost' => $this->unit_net_cost ?? null,
            'meets_required_date' => $this->meets_required_date ?? null,
            'lead_time_days' => $this->lead_time_days,
            'proposed_delivery_date' => optional($this->proposed_delivery_date)->toDateString(),
            'is_recommended' => (bool) ($this->is_recommended ?? false),
        ];
    }
}
