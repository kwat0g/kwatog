<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryRealtimeService;
use App\Modules\Accounting\Services\Statements\FinancialStatementCacheGeneration;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Flush statement caches whenever a JE materially changes (saved or deleted).
 * Tags `financial_statements` and `finance_dashboard` are written by the
 * statement services; invalidating them forces a fresh recompute on next read.
 */
class JournalEntryObserver
{
    public function deleting(JournalEntry $je): void
    {
        $status = $je->status instanceof JournalEntryStatus
            ? $je->status
            : JournalEntryStatus::tryFrom((string) $je->getRawOriginal('status'));

        if ($status !== null && $status !== JournalEntryStatus::Draft) {
            throw new BusinessRuleException('Posted and reversed journal entries cannot be archived.');
        }
    }

    public function created(JournalEntry $je): void
    {
        $this->invalidate($je, 'created');
    }

    public function updated(JournalEntry $je): void
    {
        $this->invalidate($je, $je->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(JournalEntry $je): void
    {
        $this->invalidate($je, 'deleted');
    }

    private function invalidate(JournalEntry $je, string $action): void
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

        // Register cache-generation callbacks before the outbox dispatch callback
        // so a viewer refetch cannot observe the pre-commit statement snapshot.
        app(JournalEntryRealtimeService::class)->stage($je, $action);
    }
}
