<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Events;

use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Durable recovery request for the delivery → customer-invoice handoff.
 *
 * DeliveryConfirmed remains the notification event. This narrower event is
 * emitted only when the fast-path invoice attempt did not produce a link, so
 * replaying it never re-fires unrelated confirmation notifications.
 */
class DeliveryInvoiceRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Delivery $delivery,
        public readonly string $reasonCode = 'automatic_invoice_creation_failed',
    ) {}
}
