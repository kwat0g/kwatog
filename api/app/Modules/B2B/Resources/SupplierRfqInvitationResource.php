<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Modules\Purchasing\Resources\RequestForQuoteItemResource;
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
            'status' => $rfq->status?->value ?? (string) $rfq->status, 'closes_at' => optional($rfq->closes_at)->toIso8601String(),
            'instructions' => $rfq->instructions, 'invitation_status' => $this->status?->value ?? (string) $this->status,
            'viewed_at' => optional($this->viewed_at)->toIso8601String(),
            'purchase_request' => $rfq->relationLoaded('purchaseRequest') && $rfq->purchaseRequest ? ['pr_number' => $rfq->purchaseRequest->pr_number] : null,
            'items' => RequestForQuoteItemResource::collection($rfq->items),
            'addenda' => $rfq->relationLoaded('addenda') ? $rfq->addenda->map(fn ($a) => ['id' => $a->hash_id, 'sequence' => $a->sequence, 'title' => $a->title, 'body' => $a->body, 'published_at' => optional($a->published_at)->toIso8601String()])->values()->all() : [],
            'quotes' => $this->when($rfq->relationLoaded('quotes'), fn () => SupplierQuoteResource::collection($rfq->quotes)),
        ];
    }
}
