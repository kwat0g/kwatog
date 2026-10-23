<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Mail;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use App\Common\Mail\QueuedMailable;
use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\EmailBrandingService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the supplier's portal-user email(s) (or vendor email when no
 * portal users exist) after purchasing resolves a supplier response.
 */
class SupplierPoDecisionMail extends QueuedMailable
{
    use SerializesModels;

    /** @var array<string, mixed> */
    public array $snapshot = [];

    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly PurchaseOrderResponse $response,
        public readonly string $decision,
    ) {
        // Lane: mail — see config/horizon.php. Kept off `default` so an
        // email burst cannot sit in front of an approval or a GRN notice.
        $this->onQueue('mail');
        $this->afterCommit();
        $this->snapshot = self::snapshotForEmail($purchaseOrder, $response, $decision);
    }

    public function envelope(): Envelope
    {
        $snapshot = $this->snapshotData();
        $verb = $snapshot['decision'] === 'accept' ? 'accepted' : 'returned for revision';
        $brand = app(EmailBrandingService::class)->data();
        $total = app(CurrencyDisplayService::class)->format($snapshot['total_amount']);

        return new Envelope(
            subject: $this->safeHeaderText("[{$brand['name']}] Purchase order response {$verb}: {$snapshot['po_number']} · {$total}"),
        );
    }

    public function content(): Content
    {
        $snapshot = $this->snapshotData();
        $decisionLabel = $snapshot['decision'] === 'accept' ? 'accepted' : 'returned for revision';
        $brand = app(EmailBrandingService::class)->data();
        $currency = app(CurrencyDisplayService::class);

        return new Content(
            markdown: 'emails.supplier.po-decision',
            with: [
                'brand' => $brand,
                'title' => 'Purchase order response '.$decisionLabel,
                'preheader' => "Purchasing reviewed your response for PO {$snapshot['po_number']}: {$decisionLabel}.",
                'decisionEmail' => [
                    ...$snapshot,
                    'vendor_name' => $this->safeMarkdownText($snapshot['vendor_name'], 'Supplier'),
                    'po_number' => $this->safeMarkdownText($snapshot['po_number'], 'Purchase order'),
                    'total_amount' => $currency->format($snapshot['total_amount']),
                    'proposed_delivery_date' => $snapshot['proposed_delivery_date'] ? Carbon::parse($snapshot['proposed_delivery_date'])->format('M d, Y') : null,
                    'decision_label' => $decisionLabel,
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function snapshotForEmail(PurchaseOrder $purchaseOrder, PurchaseOrderResponse $response, string $decision): array
    {
        $purchaseOrder->loadMissing('vendor');

        return [
            'po_number' => (string) $purchaseOrder->po_number,
            'purchase_order_hash_id' => (string) $purchaseOrder->hash_id,
            'total_amount' => (string) $purchaseOrder->total_amount,
            'vendor_name' => $purchaseOrder->vendor?->contact_person ?: ($purchaseOrder->vendor?->name ?: 'Supplier'),
            'response_type' => $response->response_type?->value ?? (string) $response->response_type,
            'response_type_label' => $response->response_type?->label() ?? 'Response',
            'proposed_delivery_date' => $response->proposed_delivery_date?->toDateString(),
            'decision' => $decision,
            'portal_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/supplier/purchase-orders/'.$purchaseOrder->hash_id,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotData(): array
    {
        return $this->snapshot ?: self::snapshotForEmail($this->purchaseOrder, $this->response, $this->decision);
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Purchase order response');
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
