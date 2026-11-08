<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\OutboxService;
use App\Modules\Inventory\Events\TransferOrderChanged;
use App\Modules\Inventory\Models\TransferOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable transaction-coalesced publisher for transfer-order changes. */
final class TransferOrderRealtimeService
{
    public function stage(TransferOrder $order, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new TransferOrderChanged(
            hashId: (string) $order->hash_id,
            action: $action,
            version: $order->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), "transfer-order-refresh:{$order->getKey()}:{$transactionId}");
    }
}
