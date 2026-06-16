<?php

declare(strict_types=1);

namespace App\Common\Mail;

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
}
