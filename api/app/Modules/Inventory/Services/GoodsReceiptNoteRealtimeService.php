<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\ChainBroadcaster;
use App\Common\Services\OutboxService;
use App\Modules\Inventory\Events\GoodsReceiptNoteChanged;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GoodsReceiptNoteRealtimeService
{
    public function stage(GoodsReceiptNote $grn, string $action, ?string $version = null): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $dedupeKey = "grn-refresh:{$grn->getKey()}:{$transactionId}";

        app(OutboxService::class)->recordCoalesced(new GoodsReceiptNoteChanged(
            grnHashId: (string) $grn->hash_id,
            action: $action,
            version: $version ?? $grn->updated_at?->toISOString() ?? now()->toISOString(),
            actorId: auth()->id() ? (int) auth()->id() : null,
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), $dedupeKey);

        $purchaseOrder = $grn->relationLoaded('purchaseOrder')
            ? $grn->getRelation('purchaseOrder')
            : $grn->purchaseOrder()->first();
        if ($purchaseOrder !== null) {
            app(ChainBroadcaster::class)->refreshPurchaseOrder($purchaseOrder, coalesceForTransaction: true);
        }
    }
}
