<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierQuoteItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $commercialVisible = $request->is('api/v1/b2b/supplier/*')
            || $request->user()?->hasPermission('purchasing.rfq.evaluate')
            || $request->user()?->hasPermission('purchasing.rfq.manage');

        return [
            'id' => $this->hash_id, 'response_status' => $this->response_status?->value ?? (string) $this->response_status,
            'offered_quantity' => $this->offered_quantity !== null ? (string) $this->offered_quantity : null,
            'unit_price' => $commercialVisible && $this->unit_price !== null ? (string) $this->unit_price : null,
            'line_vat_amount' => $commercialVisible ? (string) $this->line_vat_amount : null,
            'line_freight_amount' => $commercialVisible ? (string) $this->line_freight_amount : null,
            'line_other_charges' => $commercialVisible ? (string) $this->line_other_charges : null,
            'line_total_delivered_cost' => $commercialVisible ? (string) $this->line_total_delivered_cost : null,
            'lead_time_days' => $this->lead_time_days, 'proposed_delivery_date' => optional($this->proposed_delivery_date)->toDateString(),
            'compliance_status' => $this->compliance_status?->value ?? (string) $this->compliance_status, 'compliance_notes' => $this->compliance_notes,
            'rfq_item' => $this->whenLoaded('rfqItem', fn () => ['id' => $this->rfqItem->hash_id, 'description' => $this->rfqItem->description, 'quantity' => (string) $this->rfqItem->quantity, 'unit' => $this->rfqItem->unit]),
            'is_recommended' => (bool) ($this->is_recommended ?? false),
        ];
    }
}
