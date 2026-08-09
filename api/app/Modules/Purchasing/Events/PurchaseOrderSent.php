<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 2026-08-08 — Fired by PurchaseOrderService::markAsSent() (and the supplier
 * portal acknowledge path) when the PO transitions to `sent`. Drives the
 * CreateDraftGrnOnPoSent listener, which pre-stages the expected goods receipt
 * so receiving is a confirmation, not a re-entry.
 */
class PurchaseOrderSent
{
    use Dispatchable, SerializesModels;

    public function __construct(public PurchaseOrder $purchaseOrder) {}
}
