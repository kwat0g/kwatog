<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Listeners;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\Invoice;
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
        $delivery = $event->delivery->loadMissing([
            'salesOrder.customer',
        ]);
        $invoiceNumber = $event->invoiceId
            ? Invoice::query()->whereKey($event->invoiceId)->value('invoice_number')
            : null;

        $fallback = app(EmailDeliveryFailureNotifier::class);
        $context = [
            'link_to' => '/supply-chain/deliveries/'.$delivery->hash_id,
            'entity_type' => 'delivery',
            'entity_id' => $delivery->hash_id,
            'reason' => 'The customer email was missing, invalid, unreachable, or rejected by the email provider.',
        ];
        $feature = 'Customer delivery confirmation';

        if (! filter_var($delivery->salesOrder?->customer?->email, FILTER_VALIDATE_EMAIL)) {
            $fallback->notifyPermission(
                'supply_chain.view',
                $feature,
                "Delivery {$delivery->delivery_number} was confirmed, but the customer has no usable email address. Review the delivery and contact the customer through an approved channel.",
                $context,
            );

            return;
        }

        try {
            Mail::to($delivery->salesOrder->customer->email)->queue(new CustomerDeliveryConfirmedMail(
                $delivery,
                $invoiceNumber,
                $this->fallbackUserIds(),
            ));
        } catch (\Throwable $e) {
            $fallback->notifyPermission(
                'supply_chain.view',
                $feature,
                "The confirmation email for delivery {$delivery->delivery_number} could not be accepted by the email provider. Contact the customer through an approved channel.",
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
