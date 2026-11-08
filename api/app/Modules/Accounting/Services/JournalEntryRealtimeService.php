<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Events\JournalEntryChanged;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Support\JournalEntryAuditContext;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persists one private ledger refresh per journal-entry/transaction pair. */
final class JournalEntryRealtimeService
{
    public function stage(JournalEntry $journalEntry, string $action): void
    {
        $version = $journalEntry->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? $journalEntry->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $actorId = $actor instanceof User ? (int) $actor->getKey() : null;
        $actorId ??= JournalEntryAuditContext::current()['user_id'] ?? null;
        $actorId ??= $journalEntry->posted_by ?? $journalEntry->created_by;
        $actorHashId = $actorId !== null
            ? User::query()->find($actorId)?->hash_id
            : null;

        app(OutboxService::class)->recordCoalesced(new JournalEntryChanged(
            journalEntryHashId: (string) $journalEntry->hash_id,
            action: $action,
            version: $version,
            actorHashId: is_string($actorHashId) ? $actorHashId : null,
        ), "journal-entry-refresh:{$journalEntry->getKey()}:{$transactionId}");
    }
}
