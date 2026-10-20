<?php

declare(strict_types=1);

namespace App\Modules\B2B\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SupplierInvoiceStatusMail extends QueuedMailable
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
        return new Envelope(subject: 'Supplier invoice '.$this->snapshot['invoice_number'].' received');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails/supplier/invoice-status',
            with: [
                'invoiceNumber' => $this->snapshot['invoice_number'],
                'invoiceDate' => $this->snapshot['invoice_date'],
                'matchCount' => $this->snapshot['match_count'],
                'grossTotal' => $this->snapshot['gross_total'],
                'purchaseOrderNumber' => $this->snapshot['purchase_order_number'],
                'supplierName' => $this->snapshot['supplier_name'],
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
}
