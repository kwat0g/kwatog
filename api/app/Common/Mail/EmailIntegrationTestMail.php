<?php

declare(strict_types=1);

namespace App\Common\Mail;

use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailIntegrationTestMail extends QueuedMailable
{
    use SerializesModels;

    public function __construct(public readonly ?int $fallbackUserId = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ogami Philippines · AWS Mail Manager email integration test');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails/email-test',
            with: [
                'brand' => app(EmailBrandingService::class)->data(),
                'preheader' => 'Shared email shell preview text.',
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserId(
            $this->fallbackUserId,
            'AWS Mail Manager email integration test',
            'The live email test could not be delivered. Check the AWS Mail Manager SMTP configuration and queue worker.',
            ['reason' => $e->getMessage()],
        );
    }
}
