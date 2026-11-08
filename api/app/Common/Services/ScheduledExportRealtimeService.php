<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ScheduledExport;
use App\Common\Events\ScheduledExportChanged;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages coalesced, after-commit pings for every scheduled-export write path. */
final class ScheduledExportRealtimeService
{
    public function stage(ScheduledExport $export, string $action, ?string $coalesceKey = null): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $actorHashId = $actor instanceof User ? $actor->hash_id : null;

        app(OutboxService::class)->recordCoalesced(new ScheduledExportChanged(
            hashId: (string) $export->hash_id,
            ownerHashId: (string) app('hashids')->encode((int) $export->owner_id),
            action: $action,
            version: $export->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: is_string($actorHashId) ? $actorHashId : null,
        ), $coalesceKey ?? "scheduled-export-refresh:{$export->getKey()}:{$transactionId}");
    }

    /** Query-builder writes bypass Eloquent observers, so callers stage explicitly after updating. */
    public function stageById(int $exportId, string $action, ?string $coalesceKey = null): void
    {
        $export = ScheduledExport::withTrashed()->find($exportId);
        if ($export !== null) {
            $this->stage($export, $action, $coalesceKey);
        }
    }
}
