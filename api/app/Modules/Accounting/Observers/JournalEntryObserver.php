<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Support\Facades\Cache;

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

    public function saved(JournalEntry $je): void
    {
        $this->flush();
    }

    public function deleted(JournalEntry $je): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        try {
            Cache::tags(['financial_statements', 'finance_dashboard'])->flush();
        } catch (\Throwable) {
            // Some cache stores (file/database) don't support tags. Safe to ignore;
            // statements re-key by date so stale entries cap out at TTL anyway.
        }
    }
}
