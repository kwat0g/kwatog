<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Services\AccountingPeriodRealtimeService;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;

/** Publishes committed period lifecycle changes and retires finance snapshots. */
final class AccountingPeriodRealtimeObserver
{
    public function created(AccountingPeriod $period): void
    {
        $this->stage($period, 'created');
    }

    public function updated(AccountingPeriod $period): void
    {
        if (! $period->wasChanged(['status', 'closed_at', 'closed_by', 'reopened_at', 'reopened_by', 'reopen_reason'])) {
            return;
        }

        $this->stage($period, $period->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(AccountingPeriod $period): void
    {
        $this->stage($period, 'deleted');
    }

    private function stage(AccountingPeriod $period, string $action): void
    {
        // DashboardCacheGeneration registers its write after commit before the
        // outbox registers the broadcast dispatch callback.
        DashboardCacheGeneration::touch();
        app(AccountingPeriodRealtimeService::class)->stage($period, $action);
    }
}
