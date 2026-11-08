<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Events\BomChanged;
use App\Modules\MRP\Models\Bom;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one durable BOM refresh per record and database transaction. */
final class BomRealtimeService
{
    public function stage(Bom $bom, string $action): void
    {
        $version = $bom->fresh()?->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? $bom->updated_at?->format('Y-m-d\TH:i:s.uP')
            ?? now()->format('Y-m-d\TH:i:s.uP');
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $productHashId = Product::withTrashed()->find($bom->product_id)?->hash_id;
        if (! is_string($productHashId) || $productHashId === '') {
            throw new \RuntimeException("Cannot stage a BOM refresh for missing product {$bom->product_id}.");
        }

        app(OutboxService::class)->recordCoalesced(new BomChanged(
            bomHashId: (string) $bom->hash_id,
            productHashId: $productHashId,
            action: $action,
            version: $version,
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
        ), "bom-refresh:{$bom->getKey()}:{$transactionId}");
    }
}
