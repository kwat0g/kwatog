<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Mail;

use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Mail\QueuedMailable;
use App\Modules\Accounting\Models\Invoice;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerInvoiceFinalizedMail extends QueuedMailable
{
    use SerializesModels;

    private readonly array $snapshot;

    /**
     * @param  list<int>  $fallbackUserIds
     */
    public function __construct(
        public readonly Invoice $invoice,
        array $eventSnapshot,
        public readonly array $fallbackUserIds = [],
    ) {
        // Lane: mail — see config/horizon.php. Kept off `default` so an
        // email burst cannot sit in front of an approval or a GRN notice.
        $this->onQueue('mail');
        $this->afterCommit();

        $currency = app(CurrencyDisplayService::class);
        $items = collect($eventSnapshot['items'] ?? []);

        $this->snapshot = [
            'invoice_number' => $this->safeMarkdownText($eventSnapshot['invoice_number'] ?? null),
            'invoice_number_subject' => $this->safeHeaderText($eventSnapshot['invoice_number'] ?? null),
            'customer_name' => $this->safeMarkdownText(
                ($eventSnapshot['customer_contact'] ?? null) ?: ($eventSnapshot['customer_name'] ?? null),
                'there',
            ),
            'invoice_date' => $this->formatDate($eventSnapshot['invoice_date'] ?? null),
            'due_date' => $this->formatDate($eventSnapshot['due_date'] ?? null),
            'total_amount' => $currency->format($eventSnapshot['total_amount'] ?? null),
            'balance' => $currency->format($eventSnapshot['balance'] ?? null),
            'sales_order_number' => $this->safeMarkdownText($eventSnapshot['sales_order_number'] ?? null, 'Not linked'),
            'items' => $items->take(10)->map(fn (array $item): array => [
                'description' => $this->safeMarkdownText($item['description'] ?? null, 'Item'),
                'quantity' => (string) ($item['quantity'] ?? ''),
                'unit' => $this->safeMarkdownText($item['unit'] ?? null, ''),
                'unit_price' => $currency->format($item['unit_price'] ?? null),
                'total' => $currency->format($item['total'] ?? null),
            ])->all(),
            'additional_item_count' => max(0, $items->count() - 10),
            'invoice_hash_id' => (string) ($eventSnapshot['hash_id'] ?? $invoice->hash_id),
            'portal_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/customer/invoices/'.($eventSnapshot['hash_id'] ?? $invoice->hash_id),
        ];
    }

    public function envelope(): Envelope
    {
        $brand = app(EmailBrandingService::class)->data();
        $subject = '['.$brand['name'].'] Invoice issued: '.$this->snapshot['invoice_number_subject'].' · '.$this->snapshot['total_amount'];

        return new Envelope(
            subject: $this->safeHeaderText($subject),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails/customer/invoice-finalized',
            with: [
                'preheader' => 'Invoice '.$this->snapshot['invoice_number_subject'].' is ready. Review its balance and due date in the customer portal.',
                'snapshot' => $this->snapshot,
            ],
        );
    }

    private function safeMarkdownText(?string $value, string $fallback = '—'): string
    {
        $text = trim(preg_replace('/[\r\n\t]+/u', ' ', strip_tags((string) $value)) ?? '');
        if ($text === '') {
            return $fallback;
        }

        return preg_replace('/([\\\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? $fallback;
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

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer invoice delivery',
            "The finalized invoice {$this->snapshot['invoice_number_subject']} could not be emailed to the customer. Contact the customer through an approved channel.",
            [
                'link_to' => '/accounting/invoices/'.$this->snapshot['invoice_hash_id'],
                'entity_type' => 'invoice',
                'entity_id' => $this->snapshot['invoice_hash_id'],
                'reason' => 'The customer email was unreachable or the email provider rejected the invoice message.',
            ],
            $this->invoice,
        );
    }
}
