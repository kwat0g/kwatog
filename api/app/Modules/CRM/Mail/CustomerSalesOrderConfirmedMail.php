<?php

declare(strict_types=1);

namespace App\Modules\CRM\Mail;

use App\Common\Mail\QueuedMailable;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerSalesOrderConfirmedMail extends QueuedMailable
{
    use SerializesModels;

    /** @var array<string, mixed> */
    public array $snapshot = [];

    /**
     * @param  list<int>  $fallbackUserIds
     */
    public function __construct(
        public readonly SalesOrder $salesOrder,
        public readonly array $fallbackUserIds = [],
        ?array $eventSnapshot = null,
    ) {
        // Lane: mail — see config/horizon.php. Kept off `default` so an
        // email burst cannot sit in front of an approval or a GRN notice.
        $this->onQueue('mail');
        $this->afterCommit();
        $this->snapshot = $eventSnapshot ?? SalesOrderConfirmed::snapshotForEmail($salesOrder);
    }

    public function envelope(): Envelope
    {
        $snapshot = $this->snapshotData();

        return new Envelope(
            subject: "Sales Order {$snapshot['so_number']} confirmed",
        );
    }

    public function content(): Content
    {
        $snapshot = $this->snapshotData();
        $items = array_map(static fn (array $item): object => (object) [
            ...$item,
            'quantity' => number_format((float) $item['quantity'], 2),
            'product' => (object) ['name' => $item['name']],
        ], $snapshot['items']);

        return new Content(
            markdown: 'emails/customer/sales-order-confirmed',
            with: [
                'customer' => (object) ['contact_person' => $snapshot['customer_name'], 'name' => $snapshot['customer_name']],
                'salesOrder' => (object) [
                    'so_number' => $snapshot['so_number'],
                    'date' => $snapshot['date'] ? \Illuminate\Support\Carbon::parse($snapshot['date'])->format('M d, Y') : null,
                    'total_amount' => $snapshot['total_amount'],
                    'payment_terms_days' => $snapshot['payment_terms_days'],
                    'delivery_terms' => $snapshot['delivery_terms'],
                ],
                'items' => $items,
                'portalUrl' => $snapshot['portal_url'],
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        $snapshot = $this->snapshotData();
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer sales order confirmation',
            "The confirmation email for sales order {$snapshot['so_number']} could not be delivered. Contact the customer through an approved channel.",
            [
                'link_to' => '/crm/sales-orders/'.$snapshot['sales_order_hash_id'],
                'entity_type' => 'sales_order',
                'entity_id' => $snapshot['sales_order_hash_id'],
                'reason' => 'The customer email was unreachable or the email provider rejected the message.',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function snapshotData(): array
    {
        return $this->snapshot ?: SalesOrderConfirmed::snapshotForEmail($this->salesOrder);
    }
}
