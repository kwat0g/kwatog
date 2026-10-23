<?php

declare(strict_types=1);

namespace App\Modules\CRM\Mail;

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderResponse;
use App\Modules\CRM\Enums\SalesOrderResponseType;
use App\Common\Mail\QueuedMailable;
use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\EmailBrandingService;
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
        $brand = app(EmailBrandingService::class)->data();
        $total = app(CurrencyDisplayService::class)->format($snapshot['total_amount']);

        return new Envelope(
            subject: $this->safeHeaderText("[{$brand['name']}] Sales order response {$verb}: {$snapshot['so_number']} · {$total}"),
        );
    }

    public function content(): Content
    {
        $snapshot = $this->snapshotData();
        $responseType = SalesOrderResponseType::tryFrom($snapshot['response_type']);
        $brand = app(EmailBrandingService::class)->data();
        $currency = app(CurrencyDisplayService::class);
        $decisionLabel = $snapshot['decision'] === 'accept' ? 'accepted' : 'returned for revision';
        $title = 'Sales order response '.$decisionLabel;

        return new Content(
            markdown: 'emails.customer.sales-order-decision',
            with: [
                'brand' => $brand,
                'title' => $title,
                'preheader' => "Sales reviewed your response for order {$snapshot['so_number']}: {$decisionLabel}.",
                'decisionEmail' => [
                    ...$snapshot,
                    'customer_name' => $this->safeMarkdownText($snapshot['customer_name'], 'Customer'),
                    'so_number' => $this->safeMarkdownText($snapshot['so_number'], 'Sales order'),
                    'total_amount' => $currency->format($snapshot['total_amount']),
                    'response_type_label' => $responseType?->label() ?? 'Response',
                    'proposed_delivery_date' => $snapshot['proposed_delivery_date'] ? Carbon::parse($snapshot['proposed_delivery_date'])->format('M d, Y') : null,
                    'decision_label' => $decisionLabel,
                ],
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

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Sales order response');
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
