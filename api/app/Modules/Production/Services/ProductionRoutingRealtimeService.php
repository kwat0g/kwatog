<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Services\OutboxService;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Events\ProductionRoutingChanged;
use App\Modules\Production\Models\ProductRouting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Writes one durable, after-commit refresh signal for each routing mutation. */
final class ProductionRoutingRealtimeService
{
    public function stage(ProductRouting $routing, Product $product, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new ProductionRoutingChanged(
            routingHashId: (string) $routing->hash_id,
            productHashId: (string) $product->hash_id,
            action: $action,
            version: $routing->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            actorHashId: auth()->user()?->hash_id,
        ), "production-routing-refresh:{$product->getKey()}:{$transactionId}");

        app(AssignedWorkOrderRefreshService::class)->stageForProduct((int) $product->getKey());
    }
}
