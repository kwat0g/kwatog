<?php

declare(strict_types=1);

namespace App\Modules\Landing\Mail;

use InvalidArgumentException;
use App\Common\Mail\QueuedMailable;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContactInquiryReceivedMail extends QueuedMailable
{
    public function __construct(
        public readonly string $inquiryNumber,
        public readonly string $inquiryHashId,
        public readonly string $submittedAt,
        ?string $deliveryKey = null,
    ) {
        $this->deliveryKey = $deliveryKey;
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data();

        return new Envelope(
            subject: "[{$brand['name']}] New website inquiry: {$this->inquiryNumber}",
        );
    }

    public function content(): Content
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $frontendOrigin = parse_url($frontendUrl);
        if (! is_array($frontendOrigin) || ! isset($frontendOrigin['scheme'], $frontendOrigin['host'])) {
            throw new InvalidArgumentException('Configure FRONTEND_URL before sending contact inquiry email.');
        }

        return new Content(
            markdown: 'emails/contact-inquiry-received',
            with: [
                'title' => 'New website inquiry',
                'preheader' => "Inquiry {$this->inquiryNumber} is ready for review.",
                'inquiryNumber' => $this->inquiryNumber,
                'submittedAt' => $this->submittedAt,
                'inquiryUrl' => $frontendUrl.'/crm/inquiries/'.rawurlencode($this->inquiryHashId),
            ],
        );
    }

    public function failed(\Throwable $exception): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyPermission(
            'crm.inquiries.view',
            'Contact inquiry',
            "The notification for inquiry {$this->inquiryNumber} could not be delivered. Review it in the CRM inbox.",
            [
                'link_to' => '/crm/inquiries/'.$this->inquiryHashId,
                'entity_type' => 'contact_inquiry',
                'entity_id' => $this->inquiryHashId,
                'reason' => 'The configured sales inbox was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
