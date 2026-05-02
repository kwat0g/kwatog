<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Models\JournalEntry;
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
            'reference_number' => $this->reference_number,
            'cash_account'     => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? [
                'id' => $this->cashAccount->hash_id, 'code' => $this->cashAccount->code, 'name' => $this->cashAccount->name,
            ] : null),
            'journal_entry_id' => $this->journal_entry_id
                ? JournalEntry::find($this->journal_entry_id)?->hash_id
                : null,
            'created_at'       => optional($this->created_at)->toIso8601String(),
        ];
    }
}
