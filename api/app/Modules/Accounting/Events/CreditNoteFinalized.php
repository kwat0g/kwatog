<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Events;

use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Enums\CreditNoteType;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CreditNoteFinalized
{
    use Dispatchable, SerializesModels;

    public readonly array $snapshot;

    public function __construct(public readonly CreditNote $creditNote)
    {
        $creditNote->loadMissing(['customer', 'vendor', 'invoice', 'bill']);
        $isCustomer = $creditNote->type === CreditNoteType::Customer;
        $party = $isCustomer ? $creditNote->customer : $creditNote->vendor;

        $this->snapshot = [
            'type' => $creditNote->type?->value,
            'type_label' => $creditNote->type?->label(),
            'party_email' => $party?->email,
            'party_contact' => $party?->contact_person,
            'party_name' => $party?->name,
            'credit_note_number' => (string) $creditNote->credit_note_number,
            'date' => $creditNote->date?->toDateString(),
            'subtotal' => (string) $creditNote->subtotal,
            'vat_amount' => (string) $creditNote->vat_amount,
            'total_amount' => (string) $creditNote->total_amount,
            'balance' => (string) $creditNote->balance,
            'applied_amount' => (string) $creditNote->applied_amount,
            'reason' => $creditNote->reason,
            'invoice_number' => $creditNote->invoice?->invoice_number,
            'bill_number' => $creditNote->bill?->bill_number,
            'hash_id' => $creditNote->hash_id,
        ];
    }
}
