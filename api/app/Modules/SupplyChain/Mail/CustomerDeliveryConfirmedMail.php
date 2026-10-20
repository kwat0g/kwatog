<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\SupplyChain\Models\Delivery;
use App\Common\Services\EmailBrandingService;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class CustomerDeliveryConfirmedMail extends QueuedMailable
{
    use SerializesModels;

    /** @var array<string, mixed> */
    public array $snapshot = [];

    /**
     * @param  list<int>  $fallbackUserIds
     */
    public function __construct(
        public readonly Delivery $delivery,
        public readonly ?string $invoiceNumber = null,
        public readonly array $fallbackUserIds = [],
        array $snapshot = [],
        ?string $deliveryId = null,
    ) {
        $this->snapshot = $snapshot;
        $this->deliveryKey = $deliveryId;
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $snapshot = $this->snapshot !== [] ? $this->snapshot : $this->legacySnapshot();
        $brand = app(EmailBrandingService::class)->data();
        $number = $this->safeHeaderText((string) ($snapshot['delivery_number'] ?? 'Delivery'));

        return new Envelope(subject: $this->safeHeaderText("[{$brand['name']}] Delivery confirmed: {$number}"));
    }

    public function content(): Content
    {
        $snapshot = $this->snapshot !== [] ? $this->snapshot : $this->legacySnapshot();
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $brand = app(EmailBrandingService::class)->data();
        $snapshot['delivery_number'] = $this->safeMarkdownText($snapshot['delivery_number'] ?? null, 'Delivery');
        $snapshot['sales_order_number'] = $this->safeMarkdownText($snapshot['sales_order_number'] ?? null, 'Not available');
        $snapshot['customer_name'] = $this->safeMarkdownText($snapshot['customer_name'] ?? null, 'Customer');
        $snapshot['customer_contact_name'] = $this->safeMarkdownText($snapshot['customer_contact_name'] ?? null, '');
        $snapshot['invoice_number'] = $this->safeMarkdownText($snapshot['invoice_number'] ?? null, '');
        $deliveredAt = $snapshot['delivered_at'] ?? null;
        $deliveredAtDisplay = is_string($deliveredAt) && $deliveredAt !== ''
            ? Carbon::parse($deliveredAt)->timezone('Asia/Manila')->format('F j, Y g:i A').' (Asia/Manila)'
            : null;
        $title = 'Delivery confirmed';
        $preheader = $snapshot['delivery_number'].' · Confirmed';

        return new Content(
            markdown: 'emails/customer/delivery-confirmed',
            with: [
                'brand' => $brand,
                'title' => $title,
                'preheader' => $preheader,
                'emailSnapshot' => $snapshot,
                'deliveredAtDisplay' => $deliveredAtDisplay,
                'portalUrl' => $base.'/portal/customer/deliveries/'.$snapshot['delivery_hash_id'],
            ],
        );
    }

    private function safeHeaderText(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? 'Delivery confirmed');
    }

    private function safeMarkdownText(?string $value, string $fallback): string
    {
        $value = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', (string) $value) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
        if ($text === '') {
            return $fallback;
        }

        return preg_replace('/([\\\\`*_{}\[\]()#+.!|>])/u', '\\\\$1', $text) ?? $fallback;
    }

    /** @return array<string, mixed> */
    private function legacySnapshot(): array
    {
        $this->delivery->loadMissing(['salesOrder.customer']);

        return [
            'delivery_id' => (int) $this->delivery->getKey(),
            'delivery_hash_id' => (string) $this->delivery->hash_id,
            'delivery_number' => (string) $this->delivery->delivery_number,
            'sales_order_number' => (string) ($this->delivery->salesOrder?->so_number ?? ''),
            'delivered_at' => $this->delivery->delivered_at?->toIso8601String(),
            'receiver_name' => $this->delivery->receiver_name,
            'receiver_position' => $this->delivery->receiver_position,
            'delivery_remarks' => $this->delivery->delivery_remarks,
            'customer_email' => $this->delivery->salesOrder?->customer?->email,
            'customer_name' => $this->delivery->salesOrder?->customer?->name,
            'customer_contact_name' => $this->delivery->salesOrder?->customer?->contact_person,
            'invoice_number' => $this->invoiceNumber,
        ];
    }

    public function failed(\Throwable $e): void
    {
        $number = $this->snapshot['delivery_number'] ?? $this->delivery->delivery_number;
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer delivery confirmation',
            "The delivery confirmation email for {$number} could not be delivered. Contact the customer through an approved channel.",
            [
                'link_to' => '/supply-chain/deliveries/'.$this->delivery->hash_id,
                'entity_type' => 'delivery',
                'entity_id' => $this->delivery->hash_id,
                'reason' => 'The customer email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
