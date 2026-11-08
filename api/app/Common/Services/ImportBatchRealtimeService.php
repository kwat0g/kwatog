<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\ImportBatchChanged;
use App\Common\Models\ImportBatch;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces ImportBatch model changes into one after-commit refresh per transaction. */
final class ImportBatchRealtimeService
{
    public function stage(ImportBatch $batch, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $actorHashId = $actor instanceof User ? (string) $actor->hash_id : null;

        app(OutboxService::class)->recordCoalesced(new ImportBatchChanged(
            batchHashId: (string) $batch->hash_id,
            action: $action,
            version: $batch->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: $actorHashId,
        ), "import-batch-refresh:{$batch->getKey()}:{$transactionId}");
    }
}
