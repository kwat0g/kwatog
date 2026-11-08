<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Services\StockCountRealtimeService;

final class StockCountItemRealtimeObserver
{
    public function created(StockCountItem $item): void
    {
        $this->stage($item, 'created');
    }

    public function updated(StockCountItem $item): void
    {
        $this->stage($item, $item->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(StockCountItem $item): void
    {
        $this->stage($item, 'deleted');
    }

    private function stage(StockCountItem $item, string $action): void
    {
        app(StockCountRealtimeService::class)->stage($item, $action);
    }
}
