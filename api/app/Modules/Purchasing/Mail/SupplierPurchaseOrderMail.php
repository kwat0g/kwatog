<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupplierPurchaseOrderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<int>  $fallbackUserIds
     */
    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Purchase Order {$this->purchaseOrder->po_number} — action required",
        );
    }

    public function content(): Content
    {
        $vendor = $this->purchaseOrder->vendor;
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/supplier/purchase-order',
            with: [
                'vendor' => $vendor,
                'purchaseOrder' => $this->purchaseOrder,
                'items' => $this->purchaseOrder->items,
                'portalUrl' => $base.'/portal/supplier/purchase-orders/'.$this->purchaseOrder->hash_id,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Supplier purchase order',
            "Purchase order {$this->purchaseOrder->po_number} could not be delivered to the supplier. Use the supplier portal or an approved alternate channel, then confirm transmission.",
            [
                'link_to' => '/purchasing/purchase-orders/'.$this->purchaseOrder->hash_id,
                'entity_type' => 'purchase_order',
                'entity_id' => $this->purchaseOrder->hash_id,
                'reason' => 'The supplier email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
