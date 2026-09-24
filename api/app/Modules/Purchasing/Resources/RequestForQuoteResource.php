<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Policies\RequestForQuoteAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestForQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $status = $this->status;
        // Prices and supplier documents stay sealed until the RFQ closes, for
        // everyone — including the buyer who runs it.
        $sealed = ! in_array($status, [RfqStatus::Closed, RfqStatus::Awarded], true)
            || ! ($user?->hasPermission('purchasing.rfq.view') || $user?->hasPermission('purchasing.rfq.manage'));

        return [
            'id' => $this->hash_id,
            'rfq_number' => $this->rfq_number,
            'status' => $status?->value,
            'status_label' => $status?->label(),
            'title' => $this->title,
            'instructions' => $this->instructions,
            'issued_at' => optional($this->issued_at)->toIso8601String(),
            'closes_at' => optional($this->closes_at)->toIso8601String(),
            'closed_at' => optional($this->closed_at)->toIso8601String(),
            'resolved_at' => optional($this->resolved_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'last_extension_reason' => $this->last_extension_reason,
            // Comparison only: ex_vat when Ogami recovers input VAT, else gross.
            'ranking_basis' => $this->when(isset($this->ranking_basis), fn () => $this->ranking_basis),
            'purchase_request' => $this->whenLoaded('purchaseRequest', fn () => $this->purchaseRequest
                ? ['id' => $this->purchaseRequest->hash_id, 'pr_number' => $this->purchaseRequest->pr_number]
                : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator
                ? ['id' => $this->creator->hash_id, 'name' => $this->creator->name]
                : null),
            'invited_count' => (int) ($this->invitations_count ?? 0),
            'responded_count' => (int) ($this->responded_count ?? 0),
            'items' => RequestForQuoteItemResource::collection($this->whenLoaded('items')),
            'invitations' => RfqInvitationResource::collection($this->whenLoaded('invitations')),
            'quotes' => $this->whenLoaded('quotes', fn () => $sealed ? [] : SupplierQuoteResource::collection($this->quotes)),
            'awards' => RfqAwardResource::collection($this->whenLoaded('awards')),
            'documents' => $this->whenLoaded('documents', fn () => RfqDocumentResource::collection(
                $this->documents->filter(fn ($document) => $document->document_type === 'requirement_document' || ! $sealed)->values(),
            )),
            'actions' => $this->when(
                $user !== null && $this->relationLoaded('invitations'),
                fn () => app(RequestForQuoteAccessPolicy::class)->actionsFor($user, $this->resource),
            ),
        ];
    }
}
