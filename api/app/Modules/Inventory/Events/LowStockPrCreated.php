<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockPrCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Item $item,
        public PurchaseRequest $purchaseRequest,
    ) {}
}
