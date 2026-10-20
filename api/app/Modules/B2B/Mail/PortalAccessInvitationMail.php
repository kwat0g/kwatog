<?php

declare(strict_types=1);

namespace App\Modules\B2B\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalAccessInvitationMail extends QueuedMailable
{
    use SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly string $portalType,
        public readonly string $recipientName,
        public readonly string $token,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your Ogami Philippines '.$this->portalType.' portal access');
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = $base.'/portal/password-reset?type='.urlencode($this->portalType).'&token='.urlencode($this->token);

        return new Content(
            markdown: 'emails/portal/access-invitation',
            with: [
                'portalType' => $this->portalType,
                'recipientName' => $this->recipientName,
                'setupUrl' => $url,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Portal access invitation',
            "A {$this->portalType} portal invitation could not be delivered. Resend the invitation after verifying the contact's email address.",
            [
                'link_to' => '/'.($this->portalType === 'supplier' ? 'purchasing/vendors' : 'accounting/customers'),
                'entity_type' => $this->portalType.'_portal_user',
                'reason' => $e->getMessage(),
            ],
        );
    }
}
