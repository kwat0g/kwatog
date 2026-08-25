<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'payment_date'     => optional($this->payment_date)->toDateString(),
            'amount'           => (string) $this->amount,
            'payment_method'   => $this->payment_method?->value,
            'payment_method_label' => $this->payment_method?->label(),
            'reference_number' => $this->reference_number,
            'status'           => $this->status?->value,
            'status_label'     => $this->status?->label(),
            'cash_account'     => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? [
                'id' => $this->cashAccount->hash_id, 'code' => $this->cashAccount->code, 'name' => $this->cashAccount->name,
            ] : null),
            'journal_entry_id' => $this->whenLoaded('journalEntry', fn () => $this->journalEntry?->hash_id),
            'voided_at'        => optional($this->voided_at)->toIso8601String(),
            'voided_by'        => $this->whenLoaded('voidedBy', fn () => $this->voidedBy ? [
                'id' => $this->voidedBy->hash_id,
                'name' => $this->voidedBy->name,
            ] : null),
            'void_reason'      => $this->void_reason,
            'void_reversal_journal_entry' => $this->whenLoaded('voidReversalJournalEntry', fn () => $this->voidReversalJournalEntry ? [
                'id' => $this->voidReversalJournalEntry->hash_id,
                'entry_number' => $this->voidReversalJournalEntry->entry_number,
                'status' => $this->voidReversalJournalEntry->status?->value,
            ] : null),
            'replacement_payment_id' => $this->whenLoaded('replacementPayment', fn () => $this->replacementPayment?->hash_id),
            'created_at'       => optional($this->created_at)->toIso8601String(),
        ];
    }
}
