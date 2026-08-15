<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerInvoiceFinalizedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<int>  $fallbackUserIds
     */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Invoice {$this->invoice->invoice_number} issued",
        );
    }

    public function content(): Content
    {
        $customer = $this->invoice->customer;
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/customer/invoice-finalized',
            with: [
                'customer' => $customer,
                'invoice' => $this->invoice,
                'items' => $this->invoice->items,
                'salesOrder' => $this->invoice->salesOrder,
                'portalUrl' => $base.'/portal/customer/invoices/'.$this->invoice->hash_id,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer invoice delivery',
            "The finalized invoice {$this->invoice->invoice_number} could not be emailed to the customer. Contact the customer through an approved channel.",
            [
                'link_to' => '/accounting/invoices/'.$this->invoice->hash_id,
                'entity_type' => 'invoice',
                'entity_id' => $this->invoice->hash_id,
                'reason' => 'The customer email was unreachable or the email provider rejected the invoice message.',
            ],
        );
    }
}
