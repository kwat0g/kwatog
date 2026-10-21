<?php

declare(strict_types=1);

namespace App\Common\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * OGAMI-016 — batched unread-notification digest email.
 */
class NotificationDigestMail extends QueuedMailable
{
    use SerializesModels;

    public function __construct(
        public readonly ?string $recipientName,
        public readonly int $totalUnread,
        public readonly ?int $recipientUserId = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[OGAMI] Notification summary: {$this->totalUnread} unread",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.notification-digest',
            with: [
                'recipientName' => $this->recipientName,
                'totalUnread'   => $this->totalUnread,
                'preheader' => "You have {$this->totalUnread} unread notifications. Sign in to view them securely.",
                'title' => 'Notification summary',
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserId(
            $this->recipientUserId,
            'Notification digest',
            'Your notification digest email could not be delivered. Review your unread notifications in the application.',
            ['reason' => 'The email provider rejected or could not deliver the digest.'],
        );
    }
}
