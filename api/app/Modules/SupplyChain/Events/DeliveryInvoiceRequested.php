<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Events;

use App\Common\Events\ToleratesNewerModelState;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Durable recovery request for the delivery → customer-invoice handoff.
 *
 * DeliveryConfirmed remains the notification event. This narrower event is
 * emitted only when the fast-path invoice attempt did not produce a link, so
 * replaying it never re-fires unrelated confirmation notifications.
 *
 * The receipt confirmation, the invoice handoff and any later operator action
 * all write to the same delivery row. Its listener re-reads the current row and
 * guards (status must still be confirmed, no invoice linked yet), so a row that
 * moved on after publication must not fail the request forever.
 */
class DeliveryInvoiceRequested implements ToleratesNewerModelState
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Delivery $delivery,
        public readonly string $reasonCode = 'automatic_invoice_creation_failed',
    ) {}
}
