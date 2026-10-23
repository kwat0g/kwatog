<?php

declare(strict_types=1);

namespace App\Modules\CRM\Mail;

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderResponse;
use App\Modules\CRM\Enums\SalesOrderResponseType;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the customer's portal-user email(s) (or customer email when no
 * portal users exist) after sales resolves a customer response. Mirror of
 * SupplierPoDecisionMail on the purchasing side.
 */
class CustomerSalesOrderDecisionMail extends QueuedMailable
{
    use SerializesModels;

    /** @var array<string, mixed> */
    public array $snapshot = [];

    public function __construct(
        public readonly SalesOrder $salesOrder,
        public readonly SalesOrderResponse $response,
        public readonly string $decision,
    ) {
        // Lane: mail — see config/horizon.php. Kept off `default` so an
        // email burst cannot sit in front of an approval or a GRN notice.
        $this->onQueue('mail');
        $this->afterCommit();
        $this->snapshot = self::snapshotForEmail($salesOrder, $response, $decision);
    }

    public function envelope(): Envelope
    {
        $snapshot = $this->snapshotData();
        $verb = $snapshot['decision'] === 'accept' ? 'accepted' : 'returned for revision';

        return new Envelope(
            subject: "Sales Order {$snapshot['so_number']} — your response was {$verb}",
        );
    }

    public function content(): Content
    {
        $snapshot = $this->snapshotData();
        $responseType = SalesOrderResponseType::tryFrom($snapshot['response_type']);

        return new Content(
            markdown: 'emails.customer.sales-order-decision',
            with: [
                'salesOrder' => (object) [
                    'so_number' => $snapshot['so_number'],
                    'total_amount' => $snapshot['total_amount'],
                    'hash_id' => $snapshot['sales_order_hash_id'],
                    'customer' => (object) ['contact_person' => $snapshot['customer_name'], 'name' => $snapshot['customer_name']],
                ],
                'response' => (object) [
                    'response_type' => $responseType,
                    'proposed_delivery_date' => $snapshot['proposed_delivery_date'] ? Carbon::parse($snapshot['proposed_delivery_date']) : null,
                ],
                'decision' => $snapshot['decision'],
                'portalUrl' => $snapshot['portal_url'],
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function snapshotForEmail(SalesOrder $salesOrder, SalesOrderResponse $response, string $decision): array
    {
        $salesOrder->loadMissing('customer');

        return [
            'so_number' => (string) $salesOrder->so_number,
            'sales_order_hash_id' => (string) $salesOrder->hash_id,
            'total_amount' => (string) $salesOrder->total_amount,
            'customer_name' => $salesOrder->customer?->contact_person ?: ($salesOrder->customer?->name ?: 'Customer'),
            'response_type' => $response->response_type->value,
            'proposed_delivery_date' => $response->proposed_delivery_date?->toDateString(),
            'decision' => $decision,
            'portal_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/customer/orders/'.$salesOrder->hash_id,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotData(): array
    {
        return $this->snapshot ?: self::snapshotForEmail($this->salesOrder, $this->response, $this->decision);
    }
}
