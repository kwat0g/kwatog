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
 * OGAMI-016 — generic single-notification email.
 *
 * Mirrors the in-app notification envelope so the `email` channel can reuse
 * the same {title, message, link_to} payload produced by every caller of
 * NotificationService::send(). Queued so the HTTP request never blocks.
 */
class UserNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param array{title: string, message: string, link_to?: string, entity_type?: string, entity_id?: string} $data
     */
    public function __construct(
        public readonly string $notificationType,
        public readonly array $data,
        public readonly ?string $recipientName = null,
        public readonly ?int $recipientUserId = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->data['title'] ?? 'New notification';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.user-notification',
            with: [
                'title'         => $this->data['title'] ?? 'Notification',
                'body'          => $this->data['message'] ?? '',
                'linkTo'        => $this->data['link_to'] ?? null,
                'recipientName' => $this->recipientName,
                'type'          => $this->notificationType,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserId(
            $this->recipientUserId,
            'Internal notification',
            'An email copy of an in-app notification could not be delivered. Review the notification in the application.',
            [
                'link_to' => $this->data['link_to'] ?? null,
                'entity_type' => $this->data['entity_type'] ?? null,
                'entity_id' => $this->data['entity_id'] ?? null,
                'reason' => 'The email provider rejected or could not deliver the message.',
            ],
        );
    }
}
