<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C2. Fired by PurchaseOrderService::approve() the moment
 * the PO's last approval step lands. Drives the SendPOToSupplier listener.
 */
class PurchaseOrderApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public PurchaseOrder $purchaseOrder) {}
}
