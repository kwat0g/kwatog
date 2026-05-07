<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C2. Fired by PurchaseRequestService when the PR's final
 * approval lands and its status flips to `approved`. Drives the
 * ConsolidatePurchaseOrders listener.
 */
class PurchaseRequestApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public PurchaseRequest $purchaseRequest) {}
}
