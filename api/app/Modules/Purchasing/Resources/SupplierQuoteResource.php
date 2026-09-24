<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use App\Modules\Purchasing\Services\RfqCommercialCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier quotation. Sealing is decided by the caller: the RFQ resource
 * renders no quotes until close, and the portal only ever loads the
 * supplier's own quote.
 */
class SupplierQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'status' => $this->status?->value,
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'vat_treatment' => $this->vat_treatment?->value,
            'goods_amount' => $this->relationLoaded('items') ? app(RfqCommercialCalculator::class)->goods($this->items) : null,
            'freight_amount' => (string) $this->freight_amount,
            'vat_amount' => (string) $this->vat_amount,
            'total_delivered_cost' => (string) $this->total_delivered_cost,
            'quote_valid_until' => optional($this->quote_valid_until)->toDateString(),
            'is_expired' => $this->isExpired(),
            'payment_terms' => $this->payment_terms,
            'notes' => $this->notes,
            'quotation_original_filename' => $this->quotation_original_filename,
            'captured_manually' => $this->captured_by !== null,
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? ['id' => $this->vendor->hash_id, 'name' => $this->vendor->name] : null),
            'items' => SupplierQuoteItemResource::collection($this->whenLoaded('items')),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document) => [
                'id' => $document->hash_id,
                'document_type' => $document->document_type,
                'original_filename' => $document->original_filename,
            ])->values()->all()),
            'supplier_performance' => $this->when(isset($this->supplier_performance), fn () => $this->supplier_performance),
        ];
    }
}
