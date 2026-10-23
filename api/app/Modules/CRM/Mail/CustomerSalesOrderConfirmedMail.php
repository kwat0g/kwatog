<?php

declare(strict_types=1);

namespace App\Modules\CRM\Mail;

use App\Common\Mail\QueuedMailable;
use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\EmailBrandingService;
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
        $brand = app(EmailBrandingService::class)->data();
        $total = app(CurrencyDisplayService::class)->format($snapshot['total_amount']);

        return new Envelope(
            subject: $this->safeHeaderText("[{$brand['name']}] Sales order confirmed: {$snapshot['so_number']} · {$total}"),
        );
    }

    public function content(): Content
    {
        $snapshot = $this->snapshotData();
        $currency = app(CurrencyDisplayService::class);
        $visibleItems = array_slice($snapshot['items'], 0, 10);
        $items = array_map(fn (array $item): array => [
            ...$item,
            'name' => $this->safeMarkdownText($item['name'], 'Item'),
            'part_number' => $this->safeMarkdownText($item['part_number'] ?? null, ''),
            'quantity' => number_format((float) $item['quantity'], 2),
            'unit_price' => $currency->format($item['unit_price']),
            'total' => $currency->format($item['total']),
        ], $visibleItems);
        $brand = app(EmailBrandingService::class)->data();

        return new Content(
            markdown: 'emails/customer/sales-order-confirmed',
            with: [
                'brand' => $brand,
                'title' => 'Sales order confirmed',
                'preheader' => 'Sales order '.$snapshot['so_number'].' was confirmed. Review its recorded terms in the customer portal.',
                'order' => [
                    ...$snapshot,
                    'customer_name' => $this->safeMarkdownText($snapshot['customer_name'], 'Customer'),
                    'so_number' => $this->safeMarkdownText($snapshot['so_number'], 'Sales order'),
                    'delivery_terms' => $this->safeMarkdownText($snapshot['delivery_terms'] ?? null, ''),
                    'date' => $snapshot['date'] ? \Illuminate\Support\Carbon::parse($snapshot['date'])->format('M d, Y') : null,
                    'subtotal' => isset($snapshot['subtotal']) ? $currency->format($snapshot['subtotal']) : null,
                    'vat_amount' => isset($snapshot['vat_amount']) ? $currency->format($snapshot['vat_amount']) : null,
                    'total_amount' => $currency->format($snapshot['total_amount']),
                ],
                'items' => $items,
                'remainingItemCount' => max(0, count($snapshot['items']) - count($visibleItems)),
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

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Sales order confirmed');
    }

    private function safeMarkdownText(?string $value, string $fallback): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
        if ($text === '') {
            return $fallback;
        }

        return preg_replace('/([\\\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? $fallback;
    }
}
