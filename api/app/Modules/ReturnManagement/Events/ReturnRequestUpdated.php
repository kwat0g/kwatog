<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Events;

use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ReturnRequestUpdated
{
    use Dispatchable, SerializesModels;

    public array $emailSnapshot = [];

    public string $notificationId = '';

    public function __construct(
        public readonly ReturnRequest $returnRequest,
        public readonly string $action = 'updated',
    ) {
        $rma = $returnRequest->fresh() ?? $returnRequest;
        $party = $rma->type?->value === 'customer_return'
            ? ($rma->customer_id ? \App\Modules\Accounting\Models\Customer::query()->find($rma->customer_id) : null)
            : ($rma->vendor_id ? \App\Modules\Accounting\Models\Vendor::query()->find($rma->vendor_id) : null);

        $this->notificationId = (string) Str::uuid();
        $this->emailSnapshot = [
            'rma_id' => (int) $rma->getKey(),
            'hash_id' => (string) $rma->hash_id,
            'rma_number' => (string) $rma->rma_number,
            'type' => $rma->type?->value,
            'type_label' => $rma->type?->label() ?? 'Return Request',
            'status' => $rma->status?->value,
            'status_label' => $rma->status?->label() ?? 'Updated',
            'action' => $action,
            'party_email' => $party?->email,
            'party_name' => $party?->name,
            'party_contact_name' => $party?->contact_person,
        ];
    }
}
