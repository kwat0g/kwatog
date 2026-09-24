<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Modules\Purchasing\Enums\RfqInvitationStatus;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Resources\RequestForQuoteItemResource;
use App\Modules\Purchasing\Resources\SupplierQuoteResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The supplier's view of one RFQ invitation. Everything here is the
 * supplier's own: its quote, its documents, its awards. Competitor data and
 * Ogami's PR estimate are never loaded.
 */
class SupplierRfqInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rfq = $this->rfq;
        $document = static fn ($doc): array => [
            'id' => $doc->hash_id,
            'document_type' => $doc->document_type,
            'original_filename' => $doc->original_filename,
        ];
        $documents = $rfq->relationLoaded('documents') ? $rfq->documents : collect();

        return [
            'id' => $rfq->hash_id,
            'rfq_number' => $rfq->rfq_number,
            'title' => $rfq->title,
            'instructions' => $rfq->instructions,
            'status' => $rfq->status?->value,
            'status_label' => $rfq->status?->label(),
            'closes_at' => optional($rfq->closes_at)->toIso8601String(),
            'closed_at' => optional($rfq->closed_at)->toIso8601String(),
            'invitation_status' => $this->status?->value,
            'can_quote' => $rfq->status === RfqStatus::Open && $rfq->closes_at?->isFuture() === true,
            'outcome' => match (true) {
                $this->status === RfqInvitationStatus::Awarded => 'awarded',
                $this->status === RfqInvitationStatus::NotAwarded => 'not_awarded',
                $rfq->status === RfqStatus::Cancelled => 'cancelled',
                default => null,
            },
            'items' => $this->when($rfq->relationLoaded('items'), fn () => RequestForQuoteItemResource::collection($rfq->items)),
            'documents' => $this->when($rfq->relationLoaded('documents'), fn () => $documents
                ->filter(fn ($doc) => $doc->vendor_id === null)->map($document)->values()->all()),
            'my_documents' => $this->when($rfq->relationLoaded('documents'), fn () => $documents
                ->filter(fn ($doc) => $doc->vendor_id !== null)->map($document)->values()->all()),
            'quote' => $this->when($rfq->relationLoaded('quotes'), fn () => $rfq->quotes->first()
                ? new SupplierQuoteResource($rfq->quotes->first())
                : null),
            'awards' => $this->when($rfq->relationLoaded('awards'), fn () => $rfq->awards->map(fn ($award) => [
                'id' => $award->hash_id,
                'rfq_item' => $award->rfqItem ? ['id' => $award->rfqItem->hash_id, 'description' => $award->rfqItem->description] : null,
                'awarded_quantity' => (string) $award->awarded_quantity,
                'awarded_unit_price' => (string) $award->awarded_unit_price,
            ])->values()->all()),
        ];
    }
}
