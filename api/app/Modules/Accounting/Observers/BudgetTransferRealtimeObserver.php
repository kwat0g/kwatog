<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Services\BudgetTransferRealtimeService;

/** Captures request/decision writes from all Eloquent paths. */
final class BudgetTransferRealtimeObserver
{
    public function created(BudgetTransfer $transfer): void
    {
        app(BudgetTransferRealtimeService::class)->stage($transfer, 'created');
    }

    public function updated(BudgetTransfer $transfer): void
    {
        $action = $transfer->wasChanged('status') ? 'status_changed' : 'updated';
        app(BudgetTransferRealtimeService::class)->stage($transfer, $action);
    }
}
