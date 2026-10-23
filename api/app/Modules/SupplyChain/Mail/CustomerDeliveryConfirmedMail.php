<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\SupplyChain\Models\Delivery;
use App\Common\Mail\QueuedMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
        $this->onQueue('mail');
        $this->snapshot = $snapshot;
        $this->deliveryKey = $deliveryId;
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $number = $this->snapshot['delivery_number'] ?? $this->delivery->delivery_number;

        return new Envelope(subject: "Delivery {$number} confirmed");
    }

    public function content(): Content
    {
        $snapshot = $this->snapshot !== [] ? $this->snapshot : $this->legacySnapshot();
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/customer/delivery-confirmed',
            with: [
                'emailSnapshot' => $snapshot,
                'portalUrl' => $base.'/portal/customer/deliveries/'.$snapshot['delivery_hash_id'],
            ],
        );
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
