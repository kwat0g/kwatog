<?php

declare(strict_types=1);

namespace App\Modules\CRM\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Mail\QueuedMailable;
use App\Common\Services\EmailBrandingService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

class CustomerComplaintUpdateMail extends QueuedMailable
{
    /**
     * @param array<string, mixed> $snapshot
     * @param list<int> $fallbackUserIds
     */
    public function __construct(
        public readonly array $snapshot,
        public readonly array $fallbackUserIds = [],
    ) {
        // Lane: mail — see config/horizon.php. Kept off `default` so an
        // email burst cannot sit in front of an approval or a GRN notice.
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data()['name'];
        $number = $this->safeHeaderText((string) $this->snapshot['complaint_number']);

        return new Envelope(subject: $this->safeHeaderText('['.$brand.'] Customer complaint update: '.$number));
    }

    public function content(): Content
    {
        $brand = app(EmailBrandingService::class)->data();
        $brand['name'] = $this->safeMarkdownText((string) $brand['name']);
        $complaint = [
            'complaint_number' => $this->safeMarkdownText((string) ($this->snapshot['complaint_number'] ?? 'your complaint')),
            'status_label' => $this->safeMarkdownText((string) ($this->snapshot['status_label'] ?? 'Updated')),
            'received_date' => isset($this->snapshot['received_date'])
                ? $this->safeMarkdownText((string) $this->snapshot['received_date'])
                : null,
            'product_label' => isset($this->snapshot['product_label'])
                ? $this->safeMarkdownText((string) $this->snapshot['product_label'])
                : null,
            'recipient_name' => $this->safeMarkdownText((string) ($this->snapshot['recipient_name'] ?? 'Customer')),
        ];

        return new Content(
            markdown: 'emails/customer/complaint-update',
            with: [
                'brand' => $brand,
                'preheader' => 'Status update for complaint '.$complaint['complaint_number'].'.',
                'complaint' => $complaint,
                'portalUrl' => $this->snapshot['portal_url'],
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer complaint email',
            "The update email for complaint {$this->snapshot['complaint_number']} could not be delivered. Contact the customer through an approved channel.",
            [
                'link_to' => '/crm/complaints/'.$this->snapshot['complaint_hash_id'],
                'entity_type' => 'customer_complaint',
                'entity_id' => $this->snapshot['complaint_hash_id'],
                'reason' => 'The customer email was unreachable or the email provider rejected the message.',
            ],
        );
    }

    private function safeMarkdownText(string $value): HtmlString
    {
        $text = trim(preg_replace('/[\r\n\t]+/u', ' ', $value) ?? '');
        if ($text === '') {
            return new HtmlString('—');
        }

        $escaped = preg_replace('/([\\`*_{}\[\]()#+.!|])/u', '\\\\$1', $text) ?? '—';

        return new HtmlString(htmlspecialchars($escaped, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? '');
    }
}
