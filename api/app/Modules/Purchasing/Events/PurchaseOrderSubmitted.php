<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PurchaseOrderSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public PurchaseOrder $purchaseOrder) {}
}
