<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Mail\CustomerDeliveryConfirmedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailCustomerOnDeliveryConfirmed implements ShouldQueue
{
    public int $tries = 3;

    public function handle(DeliveryConfirmed $event): void
    {
        $delivery = $event->delivery;
        $snapshot = $event->emailSnapshot;
        if ($snapshot === []) {
            $delivery->loadMissing(['salesOrder.customer']);
            $snapshot = (new DeliveryConfirmed($delivery, $event->invoiceId))->emailSnapshot;
        }

        $invoiceNumber = $snapshot['invoice_number'] ?? null;
        $customerEmail = trim((string) ($snapshot['customer_email'] ?? ''));

        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/supply-chain/deliveries/'.$delivery->hash_id,
            'entity_type' => 'delivery',
            'entity_id' => $delivery->hash_id,
            'reason' => 'The customer email was missing, invalid, unreachable, or rejected by the email provider.',
        ];
        $feature = 'Customer delivery confirmation';

        if (! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $fallback->notifyPermission(
                'supply_chain.view',
                $feature,
                "Delivery {$snapshot['delivery_number']} was confirmed, but the customer has no usable email address. Review the delivery and contact the customer through an approved channel.",
                $context,
            );

            return;
        }

        try {
            $deliveryId = hash('sha256', 'customer-delivery-confirmed|'.$snapshot['delivery_id'].'|'.mb_strtolower($customerEmail));
            Mail::to($customerEmail)->queue(new CustomerDeliveryConfirmedMail(
                $delivery,
                $invoiceNumber,
                $this->fallbackUserIds(),
                $snapshot,
                $deliveryId,
            ));
        } catch (\Throwable $e) {
            $fallback->notifyPermission(
                'supply_chain.view',
                $feature,
                "The confirmation email for delivery {$snapshot['delivery_number']} could not be accepted by the email provider. Contact the customer through an approved channel.",
                $context,
            );
            Log::warning('Customer delivery email enqueue failed', [
                'delivery_id' => $delivery->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<int> */
    private function fallbackUserIds(): array
    {
        return app(EmailDeliveryFailureNotifier::class)
            ->userIdsWithPermission('supply_chain.view');
    }
}
