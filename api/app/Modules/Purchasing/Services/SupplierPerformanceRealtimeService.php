<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use App\Modules\Purchasing\Events\SupplierPerformanceChanged;
use App\Modules\Purchasing\Models\SupplierPerformanceSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Publishes snapshot refreshes that bypass Eloquent observers via upsert(). */
final class SupplierPerformanceRealtimeService
{
    public function stageSnapshot(Vendor $vendor, SupplierPerformanceSnapshot $snapshot): void
    {
        $this->invalidateSnapshotCaches();

        $transactionId = $this->transactionId();
        $actor = auth()->user();
        $actorId = $actor instanceof User ? $actor->hash_id : null;

        app(OutboxService::class)->recordCoalesced(new SupplierPerformanceChanged(
            entityId: (string) $vendor->hash_id,
            action: 'updated',
            version: $snapshot->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorId: is_string($actorId) ? $actorId : null,
        ), "supplier-performance-refresh:{$vendor->getKey()}:{$transactionId}");
    }

    /** One signal after a scheduled batch, instead of one frame per vendor. */
    public function stageBatch(int $year, int $month): void
    {
        $actor = auth()->user();
        $actorId = $actor instanceof User ? $actor->hash_id : null;
        $batchId = (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new SupplierPerformanceChanged(
            entityId: null,
            action: 'updated',
            version: sprintf('%04d-%02d:%s', $year, $month, now()->format('Y-m-d\\TH:i:s.uP')),
            actorId: is_string($actorId) ? $actorId : null,
        ), "supplier-performance-batch-refresh:{$year}-{$month}:{$batchId}");
    }

    public function invalidateSnapshotCaches(): void
    {
        DashboardCacheGeneration::touch('dashboard:purchasing');
        DashboardCacheGeneration::touch('widget-summary:purchasing.supplier_perf');
    }

    private function transactionId(): string
    {
        return DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
    }
}
