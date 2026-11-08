<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Services\StockCountRealtimeService;

final class StockCountSessionRealtimeObserver
{
    public function created(StockCountSession $session): void
    {
        $this->stage($session, 'created');
    }

    public function updated(StockCountSession $session): void
    {
        $this->stage($session, $session->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(StockCountSession $session): void
    {
        $this->stage($session, 'deleted');
    }

    private function stage(StockCountSession $session, string $action): void
    {
        app(StockCountRealtimeService::class)->stage($session, $action);
    }
}
