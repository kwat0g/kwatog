<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Mail;

use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Mail\QueuedMailable;
use App\Modules\Accounting\Models\OfficialReceipt;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerOfficialReceiptMail extends QueuedMailable
{
    use SerializesModels;

    private readonly array $snapshot;

    /** @param list<int> $fallbackUserIds */
    public function __construct(
        public readonly OfficialReceipt $officialReceipt,
        array $eventSnapshot,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();

        $this->snapshot = [
            'or_number' => $this->safeMarkdownText($eventSnapshot['or_number'] ?? null),
            'or_number_subject' => $this->safeHeaderText($eventSnapshot['or_number'] ?? null),
            'customer_name' => $this->safeMarkdownText(
                ($eventSnapshot['customer_contact'] ?? null) ?: ($eventSnapshot['customer_name'] ?? null),
                'Customer',
            ),
            'amount' => app(CurrencyDisplayService::class)->format($eventSnapshot['amount'] ?? null),
            'date' => $this->formatDate($eventSnapshot['date'] ?? null),
            'invoice_number' => $this->safeMarkdownText($eventSnapshot['invoice_number'] ?? null, 'Not linked'),
            'invoice_hash_id' => (string) ($eventSnapshot['invoice_hash_id'] ?? ''),
            'receipt_hash_id' => (string) ($eventSnapshot['receipt_hash_id'] ?? $officialReceipt->hash_id),
            'portal_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/customer/invoices/'.($eventSnapshot['invoice_hash_id'] ?? ''),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->safeHeaderText('Official receipt '.$this->snapshot['or_number_subject']));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails/customer/official-receipt',
            with: [
                'snapshot' => $this->snapshot,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Official receipt email',
            "Official receipt {$this->snapshot['or_number_subject']} could not be delivered to the customer. Send it through an approved channel.",
            [
                'link_to' => '/accounting/invoices/'.$this->snapshot['invoice_hash_id'],
                'entity_type' => 'official_receipt',
                'entity_id' => $this->snapshot['receipt_hash_id'],
                'reason' => 'The customer email was unreachable or the email provider rejected the message.',
            ],
        );
    }

    private function safeMarkdownText(?string $value, string $fallback = '—'): string
    {
        $text = trim(preg_replace('/[\r\n\t]+/u', ' ', strip_tags((string) $value)) ?? '');
        if ($text === '') {
            return $fallback;
        }

        return preg_replace('/([\\`*_{}\[\]()#+.!|>])/u', '\\$1', $text) ?? $fallback;
    }

    private function safeHeaderText(?string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags((string) $value)) ?? '');
    }

    private function formatDate(?string $value): string
    {
        return $value === null || $value === ''
            ? 'Not specified'
            : \Illuminate\Support\Carbon::parse($value)->format('M j, Y');
    }
}
