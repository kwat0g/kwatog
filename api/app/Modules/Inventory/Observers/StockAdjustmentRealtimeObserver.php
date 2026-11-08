<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Services\StockAdjustmentRealtimeService;

final class StockAdjustmentRealtimeObserver
{
    public function created(StockAdjustment $adjustment): void
    {
        $this->stage($adjustment, 'created');
    }

    public function updated(StockAdjustment $adjustment): void
    {
        $this->stage($adjustment, $adjustment->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(StockAdjustment $adjustment): void
    {
        $this->stage($adjustment, 'deleted');
    }

    private function stage(StockAdjustment $adjustment, string $action): void
    {
        app(StockAdjustmentRealtimeService::class)->stage($adjustment, $action);
    }
}
