<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\TrainingCatalogChanged;
use App\Modules\HR\Models\Training;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces training-catalog refresh signals within each record transaction. */
final class TrainingCatalogRealtimeService
{
    public function stage(Training $training, string $action): void
    {
        $actor = auth()->user();
        $actorId = $actor instanceof User && is_string($actor->hash_id) ? $actor->hash_id : null;
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new TrainingCatalogChanged(
            hashId: (string) $training->hash_id,
            action: $action,
            version: $training->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorId: $actorId,
        ), "training-catalog-refresh:{$training->getKey()}:{$transactionId}");
    }
}
