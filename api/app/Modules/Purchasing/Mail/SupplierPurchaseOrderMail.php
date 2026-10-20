<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Mail;

use App\Common\Mail\QueuedMailable;
use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SupplierPurchaseOrderMail extends QueuedMailable
{
    use SerializesModels;

    /** @var array<string, mixed> */
    public array $snapshot = [];

    /**
     * @param  list<int>  $fallbackUserIds
     */
    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->onQueue('mail');
        $this->afterCommit();
        $this->snapshot = self::snapshotForEmail($purchaseOrder);
    }

    public function envelope(): Envelope
    {
        $snapshot = $this->snapshotData();
        $brand = app(EmailBrandingService::class)->data();
        $total = app(CurrencyDisplayService::class)->format($snapshot['total_amount']);

        return new Envelope(
            subject: $this->safeHeaderText("[{$brand['name']}] Purchase order ready: {$snapshot['po_number']} · {$total}"),
        );
    }

    public function content(): Content
    {
        $snapshot = $this->snapshotData();
        $brand = app(EmailBrandingService::class)->data();
        $currency = app(CurrencyDisplayService::class);
        $visibleItems = array_slice($snapshot['items'], 0, 10);
        $items = array_map(fn (array $item): array => [
            ...$item,
            'description' => $this->safeMarkdownText($item['description'], 'Item'),
            'unit_price' => $currency->format($item['unit_price']),
            'total' => $currency->format($item['total']),
        ], $visibleItems);

        return new Content(
            markdown: 'emails.supplier.purchase-order',
            with: [
                'brand' => $brand,
                'title' => 'Purchase order approved',
                'preheader' => "Purchase order {$snapshot['po_number']} is ready. Review its terms in the supplier portal.",
                'purchaseOrderEmail' => [
                    ...$snapshot,
                    'vendor_name' => $this->safeMarkdownText($snapshot['vendor_name'], 'Supplier'),
                    'po_number' => $this->safeMarkdownText($snapshot['po_number'], 'Purchase order'),
                    'date' => $snapshot['date'] ? Carbon::parse($snapshot['date'])->format('M d, Y') : null,
                    'expected_delivery_date' => $snapshot['expected_delivery_date'] ? Carbon::parse($snapshot['expected_delivery_date'])->format('M d, Y') : null,
                    'total_amount' => $currency->format($snapshot['total_amount']),
                    'items' => $items,
                    'remainingItemCount' => max(0, count($snapshot['items']) - count($items)),
                ],
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        $snapshot = $this->snapshotData();
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Supplier purchase order',
            "Purchase order {$snapshot['po_number']} could not be delivered to the supplier. Use the supplier portal or an approved alternate channel, then confirm transmission.",
            [
                'link_to' => '/purchasing/purchase-orders/'.$snapshot['purchase_order_hash_id'],
                'entity_type' => 'purchase_order',
                'entity_id' => $snapshot['purchase_order_hash_id'],
                'reason' => 'The supplier email was unreachable or the email provider rejected the message.',
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function snapshotForEmail(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing(['vendor', 'items.item']);

        return [
            'po_number' => (string) $purchaseOrder->po_number,
            'purchase_order_hash_id' => (string) $purchaseOrder->hash_id,
            'vendor_name' => $purchaseOrder->vendor?->contact_person ?: ($purchaseOrder->vendor?->name ?: 'Supplier'),
            'date' => $purchaseOrder->date?->toDateString(),
            'expected_delivery_date' => $purchaseOrder->expected_delivery_date?->toDateString(),
            'total_amount' => (string) $purchaseOrder->total_amount,
            'payment_terms_days' => $purchaseOrder->vendor?->payment_terms_days,
            'items' => $purchaseOrder->items->map(static fn ($item): array => [
                'description' => $item->item?->name ?: ($item->description ?: 'Item'),
                'quantity' => (string) $item->quantity,
                'unit' => (string) $item->unit,
                'unit_price' => (string) $item->unit_price,
                'total' => (string) $item->total,
            ])->all(),
            'portal_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/supplier/purchase-orders/'.$purchaseOrder->hash_id,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotData(): array
    {
        return $this->snapshot ?: self::snapshotForEmail($this->purchaseOrder);
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Purchase order ready');
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
