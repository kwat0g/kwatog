<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $commercialVisible = $request->is('api/v1/b2b/supplier/*')
            || $request->user()?->hasPermission('purchasing.rfq.evaluate')
            || $request->user()?->hasPermission('purchasing.rfq.manage');

        return [
            'id' => $this->hash_id, 'version' => (int) $this->version, 'status' => $this->status?->value ?? (string) $this->status,
            'submitted_at' => optional($this->submitted_at)->toIso8601String(), 'withdrawn_at' => optional($this->withdrawn_at)->toIso8601String(),
            'is_current' => (bool) $this->is_current, 'vat_inclusive' => (bool) $this->vat_inclusive,
            'vat_amount' => $commercialVisible ? (string) $this->vat_amount : null,
            'freight_amount' => $commercialVisible ? (string) $this->freight_amount : null,
            'other_charges' => $commercialVisible ? (string) $this->other_charges : null,
            'total_delivered_cost' => $commercialVisible ? (string) $this->total_delivered_cost : null,
            'quote_valid_until' => $commercialVisible ? optional($this->quote_valid_until)->toDateString() : null,
            'payment_terms' => $commercialVisible ? $this->payment_terms : null,
            'notes' => $this->notes,
            'quotation_original_filename' => $commercialVisible ? $this->quotation_original_filename : null,
            'vendor' => $this->whenLoaded('vendor', fn () => ['id' => $this->vendor->hash_id, 'name' => $this->vendor->name]),
            'items' => SupplierQuoteItemResource::collection($this->whenLoaded('items')),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->filter(fn ($document) => $commercialVisible || $document->document_type !== 'quotation_pdf')->map(fn ($document) => [
                'id' => $document->hash_id,
                'document_type' => $document->document_type,
                'original_filename' => $document->original_filename,
            ])->values()->all()),
            'supplier_performance' => $this->when(isset($this->supplier_performance), $this->supplier_performance),
        ];
    }
}
