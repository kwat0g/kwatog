<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\Invoice;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerInvoiceFinalizedMail extends QueuedMailable
{
    use SerializesModels;

    /**
     * @param  list<int>  $fallbackUserIds
     */
    public function __construct(
        public readonly Invoice $invoice,
        private readonly array $snapshot,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Invoice {$this->snapshot['invoice_number']} issued",
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $customer = (object) [
            'contact_person' => $this->snapshot['customer_contact'] ?? null,
            'name' => $this->snapshot['customer_name'] ?? null,
        ];
        $invoice = (object) [
            'invoice_number' => $this->snapshot['invoice_number'],
            'date' => isset($this->snapshot['invoice_date']) ? \Illuminate\Support\Carbon::parse($this->snapshot['invoice_date']) : null,
            'due_date' => isset($this->snapshot['due_date']) ? \Illuminate\Support\Carbon::parse($this->snapshot['due_date']) : null,
            'total_amount' => $this->snapshot['total_amount'],
            'balance' => $this->snapshot['balance'],
        ];
        $items = collect($this->snapshot['items'])->map(static fn (array $item): object => (object) $item);
        $salesOrder = isset($this->snapshot['sales_order_number'])
            ? (object) ['so_number' => $this->snapshot['sales_order_number']]
            : null;

        return new Content(
            markdown: 'emails/customer/invoice-finalized',
            with: [
                'customer' => $customer,
                'invoiceData' => $invoice,
                'items' => $items,
                'salesOrder' => $salesOrder,
                'portalUrl' => $base.'/portal/customer/invoices/'.$this->snapshot['hash_id'],
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer invoice delivery',
            "The finalized invoice {$this->snapshot['invoice_number']} could not be emailed to the customer. Contact the customer through an approved channel.",
            [
                'link_to' => '/accounting/invoices/'.$this->snapshot['hash_id'],
                'entity_type' => 'invoice',
                'entity_id' => $this->snapshot['hash_id'],
                'reason' => 'The customer email was unreachable or the email provider rejected the invoice message.',
            ],
        );
    }
}
