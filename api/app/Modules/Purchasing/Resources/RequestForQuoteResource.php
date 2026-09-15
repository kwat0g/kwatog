<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use App\Modules\Purchasing\Enums\RfqStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestForQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status?->value ?? (string) $this->status;
        $sealed = in_array($status, [RfqStatus::Draft->value, RfqStatus::Open->value], true)
            || ! $request->user()?->hasPermission('purchasing.rfq.evaluate');
        return [
            'id' => $this->hash_id, 'rfq_number' => $this->rfq_number, 'status' => $status,
            'status_label' => $this->status?->label() ?? $status, 'title' => $this->title,
            'instructions' => $this->instructions, 'currency' => $this->currency,
            'issued_at' => optional($this->issued_at)->toIso8601String(),
            'closes_at' => optional($this->closes_at)->toIso8601String(),
            'closed_at' => optional($this->closed_at)->toIso8601String(),
            'evaluation_started_at' => optional($this->evaluation_started_at)->toIso8601String(),
            'resolved_at' => optional($this->resolved_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason, 'last_extension_reason' => $this->last_extension_reason, 'no_award_reason' => $this->no_award_reason,
            'budget_warning_level' => $this->budget_warning_level, 'budget_warning_message' => $this->budget_warning_message,
            'budget_acknowledged_at' => optional($this->budget_acknowledged_at)->toIso8601String(),
            'purchase_request' => $this->whenLoaded('purchaseRequest', fn () => $this->purchaseRequest ? [
                'id' => $this->purchaseRequest->hash_id, 'pr_number' => $this->purchaseRequest->pr_number,
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->hash_id, 'name' => $this->creator->name] : null),
            'items' => RequestForQuoteItemResource::collection($this->whenLoaded('items')),
            'invitations' => $request->user()?->hasPermission('purchasing.rfq.evaluate') || $request->user()?->hasPermission('purchasing.rfq.manage')
                ? RfqInvitationResource::collection($this->whenLoaded('invitations'))
                : [],
            'quotes' => $this->whenLoaded('quotes', fn () => $sealed ? [] : $this->quotes->map(fn ($quote) => SupplierQuoteResource::make($quote))->values()->all()),
            'awards' => RfqAwardResource::collection($this->whenLoaded('awards')),
            'documents' => $request->user()?->hasPermission('purchasing.rfq.evaluate') || $request->user()?->hasPermission('purchasing.rfq.quality_review')
                ? RfqDocumentResource::collection($this->whenLoaded('documents'))
                : [],
            'addenda' => RfqAddendumResource::collection($this->whenLoaded('addenda')),
        ];
    }
}
