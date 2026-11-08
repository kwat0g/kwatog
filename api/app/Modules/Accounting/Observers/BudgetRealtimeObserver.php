<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Services\BudgetRealtimeService;

/** Captures every Eloquent budget write, including approval and delete paths. */
final class BudgetRealtimeObserver
{
    public function created(Budget $budget): void
    {
        app(BudgetRealtimeService::class)->stage($budget, 'created');
    }

    public function updated(Budget $budget): void
    {
        $action = $budget->wasChanged('status') ? 'status_changed' : 'updated';
        app(BudgetRealtimeService::class)->stage($budget, $action);
    }

    public function deleted(Budget $budget): void
    {
        app(BudgetRealtimeService::class)->stage($budget, 'deleted');
    }
}
