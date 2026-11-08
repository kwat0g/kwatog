<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\OutboxService;
use App\Modules\Inventory\Events\StockCountChanged;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces all item/session writes into one durable session refresh per transaction. */
final class StockCountRealtimeService
{
    public function stage(Model $model, string $action): void
    {
        $session = match (true) {
            $model instanceof StockCountSession => $model,
            $model instanceof StockCountItem => $this->sessionFor($model),
            default => null,
        };
        if ($session === null) {
            return;
        }

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new StockCountChanged(
            sessionHashId: (string) $session->hash_id,
            action: $action,
            version: $model->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), "stock-count-refresh:{$session->getKey()}:{$transactionId}");
    }

    private function sessionFor(StockCountItem $item): ?StockCountSession
    {
        return $item->relationLoaded('session')
            ? $item->getRelation('session')
            : StockCountSession::query()->find((int) $item->session_id);
    }
}
