<?php

declare(strict_types=1);

namespace App\Common\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * OGAMI-016 — batched unread-notification digest email.
 */
class NotificationDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param list<array{title:string, message:string, link_to:?string, type:string, created_at:string}> $items
     */
    public function __construct(
        public readonly ?string $recipientName,
        public readonly array $items,
        public readonly int $totalUnread,
        public readonly ?int $recipientUserId = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You have {$this->totalUnread} unread notification(s)",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.notification-digest',
            with: [
                'recipientName' => $this->recipientName,
                'items'         => $this->items,
                'totalUnread'   => $this->totalUnread,
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
