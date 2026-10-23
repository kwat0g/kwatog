<?php

declare(strict_types=1);

namespace App\Modules\B2B\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\EmailBrandingService;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SupplierPurchaseOrderCancelledMail extends QueuedMailable
{
    /** @param list<int> $fallbackUserIds */
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

        return new Envelope(subject: $this->safeHeaderText('['.$brand.'] Purchase order cancelled: '.$this->snapshot['po_number']));
    }

    public function content(): Content
    {
        $poNumber = $this->safeMarkdownText((string) $this->snapshot['po_number']);
        $purchaseOrder = [
            'supplier_name' => $this->safeMarkdownText((string) $this->snapshot['supplier_name']),
            'po_number' => $poNumber,
            'cancelled_at' => $this->safeMarkdownText((string) $this->snapshot['cancelled_at']),
            'purchase_order_date' => isset($this->snapshot['purchase_order_date'])
                ? $this->safeMarkdownText((string) $this->snapshot['purchase_order_date'])
                : null,
            'display_total' => (string) $this->snapshot['display_total'],
        ];
        $brand = app(EmailBrandingService::class)->data();
        $brand['name'] = $this->safeMarkdownText((string) $brand['name']);

        return new Content(
            markdown: 'emails/supplier/purchase-order-cancelled',
            with: [
                'preheader' => 'Purchase order '.$poNumber.' was cancelled.',
                'purchaseOrder' => $purchaseOrder,
                'brand' => $brand,
                'portalUrl' => $this->snapshot['portal_url'],
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Supplier purchase order cancellation',
            "The cancellation email for PO {$this->snapshot['po_number']} could not be delivered. Notify the supplier directly.",
            [
                'link_to' => '/purchasing/purchase-orders/'.$this->snapshot['po_hash_id'],
                'entity_type' => 'purchase_order',
                'entity_id' => $this->snapshot['po_hash_id'],
                'reason' => 'The supplier email was unreachable or the email provider rejected the message.',
            ],
        );
    }

    private function safeMarkdownText(string $value): string
    {
        $text = trim(preg_replace('/[\r\n\t]+/u', ' ', $value) ?? '');

        return $text === '' ? '—' : (preg_replace('/([\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? '—');
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? '');
    }
}
