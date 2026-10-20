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
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $brand = str_replace(["\r", "\n"], ' ', (string) app(EmailBrandingService::class)->data()['name']);

        return new Envelope(subject: '['.$brand.'] Purchase order cancelled: '.$this->snapshot['po_number']);
    }

    public function content(): Content
    {
        $brand = app(EmailBrandingService::class)->data();

        return new Content(
            markdown: 'emails/supplier/purchase-order-cancelled',
            with: [
                'brand' => $brand,
                'preheader' => 'Purchase order '.$this->snapshot['po_number'].' was cancelled.',
                'purchaseOrder' => $this->snapshot,
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
}
