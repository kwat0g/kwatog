<?php

declare(strict_types=1);

namespace App\Modules\CRM\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerSalesOrderConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<int>  $fallbackUserIds
     */
    public function __construct(
        public readonly SalesOrder $salesOrder,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Sales Order {$this->salesOrder->so_number} confirmed",
        );
    }

    public function content(): Content
    {
        $customer = $this->salesOrder->customer;
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/customer/sales-order-confirmed',
            with: [
                'customer' => $customer,
                'salesOrder' => $this->salesOrder,
                'items' => $this->salesOrder->items,
                'portalUrl' => $base.'/portal/customer/orders/'.$this->salesOrder->hash_id,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer sales order confirmation',
            "The confirmation email for sales order {$this->salesOrder->so_number} could not be delivered. Contact the customer through an approved channel.",
            [
                'link_to' => '/crm/sales-orders/'.$this->salesOrder->hash_id,
                'entity_type' => 'sales_order',
                'entity_id' => $this->salesOrder->hash_id,
                'reason' => 'The customer email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
