<?php

declare(strict_types=1);

namespace App\Modules\MRP\Listeners;

use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\MRP\Jobs\RunAutomaticMrpJob;
use App\Modules\MRP\Services\MrpScopeResolver;

/** Replans only active SOs whose BOM trees consume the changed item. */
class QueueMrpOnStockMovementCompleted
{
    public function __construct(private readonly MrpScopeResolver $scopes) {}

    public function handle(StockMovementCompleted $event): void
    {
        $salesOrderIds = $this->scopes->salesOrderIdsForItems([(int) $event->movement->item_id]);
        if ($salesOrderIds === []) {
            return;
        }

        RunAutomaticMrpJob::dispatch($salesOrderIds, 'inventory_changed');
    }
}
