<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Mail;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerDeliveryConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<int>  $fallbackUserIds
     */
    public function __construct(
        public readonly Delivery $delivery,
        public readonly ?string $invoiceNumber = null,
        public readonly array $fallbackUserIds = [],
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Delivery {$this->delivery->delivery_number} confirmed",
        );
    }

    public function content(): Content
    {
        $customer = $this->delivery->salesOrder?->customer;
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            markdown: 'emails/customer/delivery-confirmed',
            with: [
                'customer' => $customer,
                'delivery' => $this->delivery,
                'salesOrder' => $this->delivery->salesOrder,
                'invoiceNumber' => $this->invoiceNumber,
                'portalUrl' => $base.'/portal/customer/deliveries/'.$this->delivery->hash_id,
            ],
        );
    }

    public function failed(\Throwable $e): void
    {
        app(EmailDeliveryFailureNotifier::class)->notifyUserIds(
            $this->fallbackUserIds,
            'Customer delivery confirmation',
            "The delivery confirmation email for {$this->delivery->delivery_number} could not be delivered. Contact the customer through an approved channel.",
            [
                'link_to' => '/supply-chain/deliveries/'.$this->delivery->hash_id,
                'entity_type' => 'delivery',
                'entity_id' => $this->delivery->hash_id,
                'reason' => 'The customer email was unreachable or the email provider rejected the message.',
            ],
        );
    }
}
