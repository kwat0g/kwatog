<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\OutboxService;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Events\ItemChanged;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces item writes into durable after-commit refresh signals. */
final class ItemRealtimeService
{
    public function stage(Item $item, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new ItemChanged(
            itemHashId: (string) $item->hash_id,
            action: $action,
            version: $item->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            actorId: auth()->id() ? (int) auth()->id() : null,
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), "item-refresh:{$item->getKey()}:{$transactionId}");

        $readinessFields = ['code', 'item_type', 'unit_of_measure', 'is_active', 'deleted_at'];
        if (in_array($action, ['created', 'deleted', 'restored'], true)
            || array_intersect(array_keys($item->getChanges()), $readinessFields) !== []) {
            $codes = collect([$item->code, $item->getPrevious()['code'] ?? null])
                ->filter(fn ($code): bool => is_string($code) && trim($code) !== '')
                ->map(fn (string $code): string => strtoupper(trim($code)))
                ->unique()
                ->values();

            if ($codes->isNotEmpty()) {
                $productIds = Product::withTrashed()
                    ->whereIn(DB::raw('UPPER(part_number)'), $codes)
                    ->pluck('id');
                $readiness = app(AssignedWorkOrderRefreshService::class);
                $productIds->each(fn ($productId) => $readiness->stageForProduct((int) $productId));
            }
        }
    }

    /** A single list/detail refresh for bulk jobs that intentionally saveQuietly(). */
    public function stageCatalog(string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new ItemChanged(
            itemHashId: null,
            action: $action,
            version: now()->format('Y-m-d\TH:i:s.uP'),
            actorId: auth()->id() ? (int) auth()->id() : null,
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), "item-catalog-refresh:{$transactionId}");
    }
}
