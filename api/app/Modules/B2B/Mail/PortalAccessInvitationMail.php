<?php

declare(strict_types=1);

namespace App\Modules\B2B\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalAccessInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly string $portalType,
        public readonly string $recipientName,
        public readonly string $temporaryPassword,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Ogami Philippines portal access');
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/portal/access-invitation',
            with: [
                'portalType' => $this->portalType,
                'recipientName' => $this->recipientName,
                'temporaryPassword' => $this->temporaryPassword,
                'portalUrl' => $base.'/portal/'.$this->portalType.'/login',
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Portal access invitation',
            "A {$this->portalType} portal invitation could not be delivered. Use an approved channel to provide the temporary password and ask the contact to reset it after signing in.",
            [
                'link_to' => '/'.($this->portalType === 'supplier' ? 'purchasing/vendors' : 'accounting/customers'),
                'entity_type' => $this->portalType.'_portal_user',
                'reason' => $e->getMessage(),
            ],
        );
    }
}
