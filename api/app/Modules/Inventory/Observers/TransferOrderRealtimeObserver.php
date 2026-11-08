<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\TransferOrder;
use App\Modules\Inventory\Services\TransferOrderRealtimeService;

/** Captures transfer-order lifecycle changes from every Eloquent write path. */
final class TransferOrderRealtimeObserver
{
    public function created(TransferOrder $order): void
    {
        $this->stage($order, 'created');
    }

    public function updated(TransferOrder $order): void
    {
        $this->stage($order, $order->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(TransferOrder $order): void
    {
        $this->stage($order, 'deleted');
    }

    private function stage(TransferOrder $order, string $action): void
    {
        app(TransferOrderRealtimeService::class)->stage($order, $action);
    }
}
