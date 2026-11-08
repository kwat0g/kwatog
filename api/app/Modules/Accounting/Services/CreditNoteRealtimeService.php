<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Events\CreditNoteChanged;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced refresh publisher for credit-note writes. */
final class CreditNoteRealtimeService
{
    public function stage(CreditNote $creditNote, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $actorId = $actor instanceof User ? (int) $actor->getKey() : null;
        $actorId ??= $creditNote->voided_by ?? $creditNote->created_by;
        $actorHashId = $actorId !== null
            ? User::query()->find($actorId)?->hash_id
            : null;

        app(OutboxService::class)->recordCoalesced(new CreditNoteChanged(
            hashId: (string) $creditNote->hash_id,
            action: $action,
            version: $creditNote->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: is_string($actorHashId) ? $actorHashId : null,
            timestamp: now()->toISOString(),
        ), "credit-note-refresh:{$creditNote->getKey()}:{$transactionId}");
    }
}
