<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Mail;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the supplier's portal-user email(s) (or vendor email when no
 * portal users exist) after purchasing resolves a supplier response.
 */
class SupplierPoDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly PurchaseOrderResponse $response,
        public readonly string $decision,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $verb = $this->decision === 'accept' ? 'accepted' : 'returned for revision';

        return new Envelope(
            subject: "PO {$this->purchaseOrder->po_number} — your response was {$verb}",
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails.supplier.po-decision',
            with: [
                'purchaseOrder' => $this->purchaseOrder,
                'response'      => $this->response,
                'decision'      => $this->decision,
                'portalUrl'     => $base.'/portal/supplier/purchase-orders/'.$this->purchaseOrder->hash_id,
            ],
        );
    }
}
