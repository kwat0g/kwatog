<?php

declare(strict_types=1);

namespace App\Common\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\EmailBrandingService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * OGAMI-016 — generic single-notification email.
 *
 * Mirrors the in-app notification envelope so the `email` channel can reuse
 * the same {title, message, link_to} payload produced by every caller of
 * NotificationService::send(). Queued so the HTTP request never blocks.
 */
class UserNotificationMail extends QueuedMailable
{
    use SerializesModels;

    /**
     * @param array{title: string, message: string, link_to?: string, entity_type?: string, entity_id?: string} $data
     */
    public function __construct(
        public readonly string $notificationType,
        public readonly array $data,
        public readonly ?string $recipientName = null,
        public readonly ?int $recipientUserId = null,
        public readonly array $recipientUserIds = [],
        public readonly array $fallbackUserIds = [],
        public readonly ?string $fallbackPermission = null,
        ?string $deliveryKey = null,
    ) {
        $this->deliveryKey = $deliveryKey;
    }

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data();
        $title = $this->safeHeaderText($this->data['title'] ?? null);
        $subject = $this->safeHeaderText("[{$brand['name']}] {$title}");

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $preheader = $this->safeHeaderText($this->data['title'] ?? null);
        $title = $this->safeText($preheader, 'Notification');
        $body = $this->safeText($this->data['message'] ?? null, 'Open OGAMI to review this notification.');
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails.user-notification',
            with: [
                'title' => $title,
                'pageTitle' => $preheader,
                'preheader' => Str::limit($preheader, 120, '…'),
                'body' => $body,
                'linkUrl' => $this->frontendLink($this->data['link_to'] ?? null, $frontendUrl),
                'recipientName' => $this->safeText($this->recipientName, 'there'),
            ],
        );
    }

    private function safeHeaderText(mixed $value): string
    {
        $value = is_string($value) && trim($value) !== '' ? $value : 'Notification';

        return Str::limit(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Notification'), 200, '…');
    }

    private function safeText(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? $value : '';
        $value = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', (string) $value) ?? '';
        $value = preg_replace('/<br\s*\/?\s*>|<\/(?:p|div|li)>/i', "\n", $value) ?? '';
        $text = trim(strip_tags($value));
        $text = preg_replace('/[^\S\r\n]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? '';
        if ($text === '') {
            return $fallback;
        }

        return preg_replace('/([\\\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? $fallback;
    }

    private function frontendLink(mixed $linkTo, string $frontendUrl): ?string
    {
        if (! is_string($linkTo) || trim($linkTo) === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $linkTo)) {
            return null;
        }

        $base = parse_url($frontendUrl);
        $link = parse_url($linkTo);
        if (! is_array($base) || ! is_array($link) || ! isset($base['scheme'], $base['host'])) {
            return null;
        }

        if (isset($link['scheme']) || isset($link['host'])) {
            $sameOrigin = strtolower((string) ($link['scheme'] ?? '')) === strtolower($base['scheme'])
                && strtolower((string) ($link['host'] ?? '')) === strtolower($base['host'])
                && ($link['port'] ?? null) === ($base['port'] ?? null)
                && ! isset($link['user'])
                && ! isset($link['pass']);
            if (! $sameOrigin) {
                return null;
            }
        }

        $path = $link['path'] ?? '';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $frontendUrl.$path
            .(isset($link['query']) ? '?'.$link['query'] : '')
            .(isset($link['fragment']) ? '#'.$link['fragment'] : '');
    }

    public function failed(\Throwable $e): void
    {
        $failureNotifier = app(EmailDeliveryFailureNotifier::class);
        $message = 'An email copy of an in-app notification could not be delivered. Review the notification in the application.';
        $context = [
            'link_to' => $this->data['link_to'] ?? null,
            'entity_type' => $this->data['entity_type'] ?? null,
            'entity_id' => $this->data['entity_id'] ?? null,
            'reason' => 'The email provider rejected or could not deliver the message.',
        ];

        if ($this->fallbackPermission !== null) {
            $failureNotifier->notifyPermission($this->fallbackPermission, 'Customer delivery-date proposal', $message, $context);

            return;
        }

        if ($this->fallbackUserIds !== []) {
            $failureNotifier->notifyUserIds($this->fallbackUserIds, 'Customer delivery-date proposal', $message, $context);

            return;
        }

        if ($this->recipientUserIds !== []) {
            $failureNotifier->notifyUserIds($this->recipientUserIds, 'Internal notification', $message, $context);

            return;
        }

        $failureNotifier->notifyUserId($this->recipientUserId, 'Internal notification', $message, $context);
    }
}
