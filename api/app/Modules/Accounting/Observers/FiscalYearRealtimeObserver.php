<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\BudgetRealtimeService;

/** Fiscal-year edits refresh budget selectors and period-dependent summaries. */
final class FiscalYearRealtimeObserver
{
    public function created(FiscalYear $fiscalYear): void
    {
        app(BudgetRealtimeService::class)->stageDomainRefresh(
            'created',
            $fiscalYear->updated_at?->format('Y-m-d\\TH:i:s.uP'),
        );
    }

    public function updated(FiscalYear $fiscalYear): void
    {
        $action = $fiscalYear->wasChanged('status') ? 'status_changed' : 'updated';
        $realtime = app(BudgetRealtimeService::class);
        $realtime->stageDomainRefresh(
            $action,
            $fiscalYear->updated_at?->format('Y-m-d\\TH:i:s.uP'),
        );
        $realtime->stageFiscalYearBudgets((int) $fiscalYear->getKey());
    }
}
