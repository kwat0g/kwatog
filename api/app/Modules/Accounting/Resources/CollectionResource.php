<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'collection_date'  => optional($this->collection_date)->toDateString(),
            'amount'           => (string) $this->amount,
            'payment_method'   => $this->payment_method?->value,
            'payment_method_label' => $this->payment_method?->label(),
            'reference_number' => $this->reference_number,
            'cash_account'     => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? [
                'id' => $this->cashAccount->hash_id, 'code' => $this->cashAccount->code, 'name' => $this->cashAccount->name,
            ] : null),
            // Do not query once per collection. Callers that need this field
            // eager-load journalEntry; an unloaded relation is deliberately
            // represented as null rather than triggering N+1 IO here.
            'journal_entry_id' => $this->relationLoaded('journalEntry')
                ? $this->journalEntry?->hash_id
                : null,
            'created_at'       => optional($this->created_at)->toIso8601String(),
        ];
    }
}
