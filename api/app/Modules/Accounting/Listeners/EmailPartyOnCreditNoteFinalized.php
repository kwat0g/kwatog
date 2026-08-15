<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Enums\CreditNoteType;
use App\Modules\Accounting\Events\CreditNoteFinalized;
use App\Modules\Accounting\Mail\PartyCreditNoteMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailPartyOnCreditNoteFinalized implements ShouldQueue
{
    public int $tries = 3;

    public function handle(CreditNoteFinalized $event): void
    {
        $creditNote = $event->creditNote->loadMissing(['customer', 'vendor', 'invoice', 'bill', 'lines']);
        $isCustomer = $creditNote->type === CreditNoteType::Customer;
        $party = $isCustomer ? $creditNote->customer : $creditNote->vendor;
        $permission = $isCustomer ? 'accounting.invoices.view' : 'accounting.vendors.view';
        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/accounting/credit-notes/'.$creditNote->hash_id,
            'entity_type' => 'credit_note',
            'entity_id' => $creditNote->hash_id,
            'reason' => 'The party email was missing, invalid, unreachable, or rejected by the email provider.',
        ];
        $partyLabel = $isCustomer ? 'customer' : 'supplier';

        if (! filter_var($party?->email, FILTER_VALIDATE_EMAIL)) {
            $fallback->notifyPermission(
                $permission,
                'Credit note email',
                "Credit note {$creditNote->credit_note_number} was finalized, but the {$partyLabel} has no usable email address. Send it through an approved channel.",
                $context,
            );
            return;
        }

        try {
            Mail::to($party->email)->queue(new PartyCreditNoteMail(
                $creditNote,
                $fallback->userIdsWithPermission($permission),
            ));
        } catch (\Throwable $e) {
            $fallback->notifyPermission(
                $permission,
                'Credit note email',
                "Credit note {$creditNote->credit_note_number} could not be accepted by the email provider. Send it through an approved channel.",
                $context,
            );
            Log::warning('Credit note email enqueue failed', [
                'credit_note_id' => $creditNote->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
