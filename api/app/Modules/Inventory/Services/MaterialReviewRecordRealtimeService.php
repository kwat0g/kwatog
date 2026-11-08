<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\OutboxService;
use App\Modules\Inventory\Events\MaterialReviewRecordChanged;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces one MRB's writes into a durable after-commit refresh ping. */
final class MaterialReviewRecordRealtimeService
{
    public function stage(MaterialReviewRecord $record, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new MaterialReviewRecordChanged(
            entityHashId: (string) $record->hash_id,
            action: $action,
            version: $record->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), "material-review-record-refresh:{$record->getKey()}:{$transactionId}");
    }
}
