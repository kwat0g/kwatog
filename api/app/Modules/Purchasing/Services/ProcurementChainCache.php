<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Support\CacheGeneration;
use App\Common\Support\FailOpenCache;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Versioned cache for the bounded aggregate summaries exposed by ProcurementChainController. */
final class ProcurementChainCache
{
    private const VERSION_TTL_SECONDS = 604800;

    /** @param callable(): mixed $compute */
    public function remember(string $key, int $ttlSeconds, callable $compute): mixed
    {
        $versionKey = $this->versionKey($key);
        $version = CacheGeneration::currentDefault(
            $versionKey,
            self::VERSION_TTL_SECONDS,
            'purchasing.procurement_chain',
        );

        return FailOpenCache::remember(
            Cache::store(),
            $this->summaryKey($key, $version),
            $ttlSeconds,
            $compute,
            'purchasing.procurement_chain',
        );
    }

    /** Rotate generations after commit so an in-flight old read cannot repopulate the live key. */
    public function invalidateFor(Model $model): void
    {
        $keys = match (true) {
            $model instanceof PurchaseRequest => ['procurement_chain_pr'],
            $model instanceof PurchaseOrder => ['procurement_chain_po'],
            $model instanceof GoodsReceiptNote => ['procurement_chain_grn'],
            $model instanceof Bill => ['procurement_chain_bills', 'procurement_chain_three_way'],
            default => [],
        };

        foreach ($keys as $key) {
            $this->rotate($key);
        }
    }

    /** Retire every bounded procurement summary generation after commit. */
    public function invalidateAll(): bool
    {
        $rotated = true;
        foreach (['procurement_chain_pr', 'procurement_chain_po', 'procurement_chain_grn', 'procurement_chain_bills', 'procurement_chain_three_way'] as $key) {
            $rotated = $this->rotate($key) && $rotated;
        }

        return $rotated;
    }

    public function versionKey(string $key): string
    {
        return "procurement_chain:{$key}:version";
    }

    public function initialSummaryKey(string $key): string
    {
        return $this->summaryKey($key, 'initial');
    }

    private function summaryKey(string $key, string $version): string
    {
        return "procurement_chain:{$key}:v{$version}";
    }

    private function rotate(string $key): bool
    {
        return CacheGeneration::rotateDefault(
            $this->versionKey($key),
            self::VERSION_TTL_SECONDS,
            'purchasing.procurement_chain.'.$key,
        );
    }
}
