<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\OfficialReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerOfficialReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly OfficialReceipt $officialReceipt,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Official receipt {$this->officialReceipt->or_number}");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/customer/official-receipt',
            with: [
                'receipt' => $this->officialReceipt,
                'portalUrl' => $base.'/portal/customer/invoices/'.($this->officialReceipt->invoice?->hash_id ?? ''),
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Official receipt email',
            "Official receipt {$this->officialReceipt->or_number} could not be delivered to the customer. Send it through an approved channel.",
            [
                'link_to' => '/accounting/invoices/'.($this->officialReceipt->invoice?->hash_id ?? ''),
                'entity_type' => 'official_receipt',
                'entity_id' => $this->officialReceipt->hash_id,
                'reason' => 'The customer email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
