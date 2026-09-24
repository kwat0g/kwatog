<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\RequestForQuoteInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SupplierRfqLifecycleMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly RequestForQuote $rfq,
        public readonly RequestForQuoteInvitation $invitation,
        public readonly string $kind,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->kind) {
            'published' => "Request for quotation {$this->rfq->rfq_number}",
            'extended' => "RFQ {$this->rfq->rfq_number}: deadline extended",
            'awarded' => "RFQ {$this->rfq->rfq_number}: result",
            'cancelled' => "RFQ {$this->rfq->rfq_number} cancelled",
            default => "RFQ {$this->rfq->rfq_number} update",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails/supplier/rfq-lifecycle',
            with: [
                'rfq' => $this->rfq,
                'invitation' => $this->invitation,
                'kind' => $this->kind,
                'portalUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/supplier/rfqs/'.$this->rfq->hash_id,
            ],
        );
    }

    public function failed(\Throwable $exception): void
    {
        $this->invitation->forceFill(['last_notification_error' => mb_substr($exception->getMessage(), 0, 2000)])->save();
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Supplier RFQ',
            "Supplier RFQ {$this->rfq->rfq_number} email delivery failed; review portal delivery state.",
            ['link_to' => '/purchasing/rfqs/'.$this->rfq->hash_id, 'entity_type' => 'request_for_quote', 'entity_id' => $this->rfq->hash_id],
        );
    }
}
