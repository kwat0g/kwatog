<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\CreditNote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartyCreditNoteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly CreditNote $creditNote,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Credit note {$this->creditNote->credit_note_number} finalized");
    }

    public function content(): Content
    {
        $isCustomer = $this->creditNote->type?->value === 'customer';
        $party = $isCustomer ? $this->creditNote->customer : $this->creditNote->vendor;
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/finance/credit-note-finalized',
            with: [
                'creditNote' => $this->creditNote,
                'party' => $party,
                'portalUrl' => $base.($isCustomer ? '/portal/customer' : '/portal/supplier'),
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Credit note email',
            "Credit note {$this->creditNote->credit_note_number} could not be delivered to the party. Send it through an approved channel.",
            [
                'link_to' => '/accounting/credit-notes/'.$this->creditNote->hash_id,
                'entity_type' => 'credit_note',
                'entity_id' => $this->creditNote->hash_id,
                'reason' => 'The party email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
