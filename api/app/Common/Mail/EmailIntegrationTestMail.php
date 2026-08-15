<?php

declare(strict_types=1);

namespace App\Common\Mail;

use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailIntegrationTestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ?int $fallbackUserId = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ogami Philippines · Brevo email integration test');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails/email-test',
            with: ['brand' => app(EmailBrandingService::class)->data()],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserId(
            $this->fallbackUserId,
            'Brevo email integration test',
            'The live email test could not be delivered. Check the Brevo SMTP configuration and queue worker.',
            ['reason' => $e->getMessage()],
        );
    }
}
