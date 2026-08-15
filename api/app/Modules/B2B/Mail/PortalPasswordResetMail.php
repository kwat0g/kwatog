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

class PortalPasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly string $portalType,
        public readonly string $token,
        public readonly string $recipientName,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Ogami Philippines portal password');
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = $base.'/portal/password-reset?type='.urlencode($this->portalType).'&token='.urlencode($this->token);

        return new Content(
            markdown: 'emails/portal/password-reset',
            with: [
                'portalType' => $this->portalType,
                'recipientName' => $this->recipientName,
                'resetUrl' => $url,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        $permission = $this->portalType === 'supplier' ? 'accounting.vendors.view' : 'accounting.customers.view';

        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Portal password reset email',
            'A portal password reset message could not be delivered. Contact the portal user through an approved channel and verify the account before assisting with access.',
            [
                'link_to' => $this->portalType === 'supplier' ? '/purchasing/vendors' : '/accounting/customers',
                'entity_type' => $this->portalType.'_portal_user',
                'reason' => $e->getMessage(),
                'permission' => $permission,
            ],
        );
    }
}
