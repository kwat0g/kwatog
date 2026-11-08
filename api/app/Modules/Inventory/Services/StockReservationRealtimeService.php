<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\OutboxService;
use App\Modules\Inventory\Events\StockReservationChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces one content-free stock refresh per item/location transaction. */
final class StockReservationRealtimeService
{
    public function stage(int $itemId, int $locationId, string $action): void
    {
        // The reservation count is visible to dashboard readers. Invalidate it
        // at the source write; outbox delivery is asynchronous by design.
        app(PublishInventoryStockChange::class)->invalidateSourceCaches();
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new StockReservationChanged($itemId, $locationId, $action),
            "stock-reservation-refresh:{$itemId}:{$locationId}:{$transactionId}",
        );
    }
}
