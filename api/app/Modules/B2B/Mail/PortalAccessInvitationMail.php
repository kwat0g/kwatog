<?php

declare(strict_types=1);

namespace App\Modules\B2B\Mail;

use App\Common\Mail\QueuedMailable;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;
use Illuminate\Queue\SerializesModels;

class PortalAccessInvitationMail extends QueuedMailable
{
    use SerializesModels;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly string $portalType,
        public readonly string $recipientName,
        public readonly string $token,
        public readonly int $subjectId,
        public readonly string $subjectLink,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data()['name'];
        $portal = ucfirst($this->portalType);

        return new Envelope(subject: $this->safeHeaderText('['.$brand.'] Set up '.$portal.' portal access'));
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = $base.'/portal/password-reset?type='.urlencode($this->portalType).'&token='.urlencode($this->token);
        $brand = app(EmailBrandingService::class)->data();
        $brand['name'] = $this->safeMarkdownText((string) $brand['name']);

        return new Content(
            markdown: 'emails/portal/access-invitation',
            with: [
                'brand' => $brand,
                'preheader' => 'Set a private password to activate your '.$this->portalType.' portal access.',
                'portalType' => $this->portalType,
                'safeRecipientName' => $this->safeMarkdownText($this->recipientName),
                'setupUrl' => $url,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        $subject = match ($this->portalType) {
            'customer' => Customer::query()->find($this->subjectId),
            'supplier' => Vendor::query()->find($this->subjectId),
            default => null,
        };

        if ($subject === null) {
            return;
        }

        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Portal access invitation',
            "A {$this->portalType} portal invitation could not be delivered. Resend the invitation after verifying the contact's email address.",
            [
                'link_to' => $this->subjectLink,
                'entity_type' => $this->portalType.'_portal_user',
                'reason' => 'The email provider rejected or could not deliver the portal invitation.',
            ],
            $subject,
        );
    }

    private function safeMarkdownText(string $value): HtmlString
    {
        $text = trim(preg_replace('/[\r\n\t]+/u', ' ', $value) ?? '');
        if ($text === '') {
            return new HtmlString('there');
        }

        $escaped = preg_replace('/([\\`*_{}\[\]()#+.!|])/u', '\\\\$1', $text) ?? 'there';

        return new HtmlString(htmlspecialchars($escaped, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? '');
    }
}
