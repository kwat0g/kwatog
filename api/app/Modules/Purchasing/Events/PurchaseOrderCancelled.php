<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 3 M-12. Fired by PurchaseOrderService::cancel() once the PO is
 * marked Cancelled. Mirrors PurchaseOrderApproved so future listeners
 * (e.g. notify supplier, free reservations) can subscribe without the
 * service knowing about them.
 */
class PurchaseOrderCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(public PurchaseOrder $purchaseOrder) {}
}
