<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Modules\Purchasing\Resources\RequestForQuoteItemResource;
use App\Modules\Purchasing\Resources\RfqAwardResource;
use App\Modules\Purchasing\Resources\SupplierQuoteResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierRfqInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rfq = $this->rfq;

        return [
            'id' => $rfq->hash_id, 'rfq_number' => $rfq->rfq_number, 'title' => $rfq->title,
            'status' => $rfq->status?->value ?? (string) $rfq->status,
            'status_label' => $rfq->status?->label() ?? (string) $rfq->status,
            'closes_at' => optional($rfq->closes_at)->toIso8601String(),
            'closed_at' => optional($rfq->closed_at)->toIso8601String(),
            'resolved_at' => optional($rfq->resolved_at)->toIso8601String(),
            'no_award_reason' => $rfq->no_award_reason,
            'instructions' => $rfq->instructions, 'invitation_status' => $this->status?->value ?? (string) $this->status,
            'viewed_at' => optional($this->viewed_at)->toIso8601String(),
            'purchase_request' => $rfq->relationLoaded('purchaseRequest') && $rfq->purchaseRequest ? ['pr_number' => $rfq->purchaseRequest->pr_number] : null,
            'items' => RequestForQuoteItemResource::collection($rfq->items),
            'addenda' => $rfq->relationLoaded('addenda') ? $rfq->addenda->map(fn ($a) => ['id' => $a->hash_id, 'sequence' => $a->sequence, 'title' => $a->title, 'body' => $a->body, 'published_at' => optional($a->published_at)->toIso8601String()])->values()->all() : [],
            'quotes' => $this->when($rfq->relationLoaded('quotes'), fn () => SupplierQuoteResource::collection($rfq->quotes)),
            'awards' => $rfq->relationLoaded('awards')
                ? RfqAwardResource::collection($rfq->awards)
                : [],
            'documents' => $rfq->relationLoaded('documents')
                ? $rfq->documents->map(fn ($document) => [
                    'id' => $document->hash_id,
                    'document_type' => $document->document_type,
                    'original_filename' => $document->original_filename,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => (int) $document->size_bytes,
                ])->values()->all()
                : [],
        ];
    }
}
