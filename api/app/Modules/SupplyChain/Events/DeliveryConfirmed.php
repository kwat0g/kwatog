<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Events;

use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Task A4 — Fired by DeliveryService::confirm() once the customer has
 * confirmed receipt. The optional invoice ID is populated when the fast
 * invoice handoff succeeds; a separate DeliveryInvoiceRequested event is
 * emitted when Accounting recovery is required.
 */
class DeliveryConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Delivery $delivery,
        public readonly ?int $invoiceId = null,
    ) {}
}
