<?php

declare(strict_types=1);

namespace App\Modules\B2B\Mail;

use App\Common\Mail\QueuedMailable;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalPasswordResetMail extends QueuedMailable
{
    use SerializesModels;

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
        $brand = app(EmailBrandingService::class)->data()['name'];

        return new Envelope(subject: $this->safeHeaderText('['.$brand.'] Reset your '.$this->portalType.' portal password'));
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = $base.'/portal/password-reset?type='.urlencode($this->portalType).'&token='.urlencode($this->token);
        $brand = app(EmailBrandingService::class)->data();
        $brand['name'] = $this->safeMarkdownText((string) $brand['name']);

        return new Content(
            markdown: 'emails/portal/password-reset',
            with: [
                'brand' => $brand,
                'preheader' => 'Use your one-time link to reset your '.$this->portalType.' portal password.',
                'portalType' => $this->portalType,
                'recipientName' => $this->safeMarkdownText($this->recipientName),
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
                'reason' => 'The email provider rejected or could not deliver the portal password reset message.',
                'permission' => $permission,
            ],
        );
    }

    private function safeMarkdownText(string $value): string
    {
        $text = trim(preg_replace('/[\r\n\t]+/u', ' ', strip_tags($value)) ?? '');
        if ($text === '') {
            return 'there';
        }

        return preg_replace('/([\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? 'there';
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? '');
    }
}
