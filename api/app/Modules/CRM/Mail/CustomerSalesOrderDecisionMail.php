<?php

declare(strict_types=1);

namespace App\Modules\CRM\Mail;

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the customer's portal-user email(s) (or customer email when no
 * portal users exist) after sales resolves a customer response. Mirror of
 * SupplierPoDecisionMail on the purchasing side.
 */
class CustomerSalesOrderDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly SalesOrder $salesOrder,
        public readonly SalesOrderResponse $response,
        public readonly string $decision,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $verb = $this->decision === 'accept' ? 'accepted' : 'returned for revision';

        return new Envelope(
            subject: "Sales Order {$this->salesOrder->so_number} — your response was {$verb}",
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails.customer.sales-order-decision',
            with: [
                'salesOrder' => $this->salesOrder,
                'response'   => $this->response,
                'decision'   => $this->decision,
                'portalUrl'  => $base.'/portal/customer/orders/'.$this->salesOrder->hash_id,
            ],
        );
    }
}
