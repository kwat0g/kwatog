<?php

declare(strict_types=1);

namespace App\Modules\B2B\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\EmailBrandingService;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

class SupplierInvoiceStatusMail extends QueuedMailable
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

        return new Envelope(subject: $this->safeHeaderText('['.$brand.'] Supplier invoice received: '.$this->snapshot['invoice_number']));
    }

    public function content(): Content
    {
        $brand = app(EmailBrandingService::class)->data();
        $brand['name'] = $this->safeMarkdownText((string) $brand['name']);
        $invoiceNumber = $this->safeMarkdownText((string) $this->snapshot['invoice_number']);

        return new Content(
            markdown: 'emails/supplier/invoice-status',
            with: [
                'brand' => $brand,
                'preheader' => 'A supplier invoice was received for review.',
                'invoiceNumber' => $invoiceNumber,
                'invoiceDate' => $this->snapshot['invoice_date'],
                'matchCount' => $this->snapshot['match_count'],
                'grossTotal' => $this->snapshot['display_total'],
                'purchaseOrderNumber' => $this->snapshot['purchase_order_number'] === null
                    ? null
                    : $this->safeMarkdownText((string) $this->snapshot['purchase_order_number']),
                'supplierName' => $this->safeMarkdownText((string) $this->snapshot['supplier_name']),
                'portalUrl' => $this->snapshot['portal_url'],
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Supplier invoice email',
            'The confirmation email for supplier invoice '.$this->snapshot['invoice_number'].' could not be delivered. Review the submission and use the supplier portal or an approved channel.',
            [
                'link_to' => '/accounting/bills/'.$this->snapshot['bill_hash_id'],
                'entity_type' => 'bill',
                'entity_id' => $this->snapshot['bill_hash_id'],
                'reason' => 'The supplier email was unreachable or the email provider rejected the message.',
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
