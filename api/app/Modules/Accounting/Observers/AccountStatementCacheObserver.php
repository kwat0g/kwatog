<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\Statements\FinancialStatementCacheGeneration;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Chart changes affect cached statement labels and finance dashboard summaries. */
final class AccountStatementCacheObserver
{
    public function created(Account $account): void
    {
        $this->invalidate();
    }

    public function updated(Account $account): void
    {
        $this->invalidate();
    }

    public function deleted(Account $account): void
    {
        $this->invalidate();
    }

    private function invalidate(): void
    {
        FinancialStatementCacheGeneration::rotateAfterCommit();
        DashboardCacheGeneration::touch();

        DB::afterCommit(static function (): void {
            try {
                Cache::tags(['financial_statements', 'finance_dashboard'])->flush();
            } catch (\Throwable) {
                // Some cache stores do not support tags; versioned keys still retire safely.
            }
        });
    }
}
