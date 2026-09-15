<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id, 'version' => (int) $this->version, 'status' => $this->status?->value ?? (string) $this->status,
            'submitted_at' => optional($this->submitted_at)->toIso8601String(), 'withdrawn_at' => optional($this->withdrawn_at)->toIso8601String(),
            'is_current' => (bool) $this->is_current, 'vat_inclusive' => (bool) $this->vat_inclusive,
            'vat_amount' => (string) $this->vat_amount, 'freight_amount' => (string) $this->freight_amount,
            'other_charges' => (string) $this->other_charges, 'total_delivered_cost' => (string) $this->total_delivered_cost,
            'quote_valid_until' => optional($this->quote_valid_until)->toDateString(), 'payment_terms' => $this->payment_terms, 'notes' => $this->notes,
            'quotation_original_filename' => $this->quotation_original_filename,
            'vendor' => $this->whenLoaded('vendor', fn () => ['id' => $this->vendor->hash_id, 'name' => $this->vendor->name]),
            'items' => SupplierQuoteItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
