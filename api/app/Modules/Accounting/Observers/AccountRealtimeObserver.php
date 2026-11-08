<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountRealtimeService;

/** Captures chart changes from every Eloquent write path. */
final class AccountRealtimeObserver
{
    public function created(Account $account): void
    {
        app(AccountRealtimeService::class)->stage($account, 'created');
    }

    public function updated(Account $account): void
    {
        $action = $account->wasChanged('is_active') ? 'status_changed' : 'updated';
        app(AccountRealtimeService::class)->stage($account, $action);
    }

    public function deleted(Account $account): void
    {
        app(AccountRealtimeService::class)->stage($account, 'deleted');
    }
}
