<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Services\AutoReplenishmentService;

class CheckReorderPoint
{
    public function __construct(private readonly AutoReplenishmentService $replenisher) {}

    public function handle(StockMovementCompleted $event): void
    {
        $this->replenisher->checkAndReplenish((int) $event->movement->item_id);
    }
}
