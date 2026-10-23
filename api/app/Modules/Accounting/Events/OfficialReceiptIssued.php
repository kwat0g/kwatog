<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Events;

use App\Modules\Accounting\Models\OfficialReceipt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class OfficialReceiptIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public readonly array $snapshot;

    public function __construct(public readonly OfficialReceipt $officialReceipt)
    {
        $officialReceipt->loadMissing(['customer', 'invoice']);

        $this->snapshot = [
            'or_number' => (string) $officialReceipt->or_number,
            'customer_email' => $officialReceipt->customer?->email,
            'customer_contact' => $officialReceipt->customer?->contact_person,
            'customer_name' => $officialReceipt->customer?->name,
            'amount' => (string) $officialReceipt->amount,
            'date' => $officialReceipt->date?->toDateString(),
            'invoice_number' => $officialReceipt->invoice?->invoice_number,
            'invoice_hash_id' => $officialReceipt->invoice?->hash_id,
            'receipt_hash_id' => $officialReceipt->hash_id,
        ];
    }
}
