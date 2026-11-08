<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Observers;

use App\Modules\Purchasing\Services\ProcurementChainCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the P2P aggregate summaries aligned with their persisted source rows.
 * Laravel defers these observer callbacks until the outer transaction commits.
 */
final class ProcurementChainCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        app(ProcurementChainCache::class)->invalidateFor($model);
    }

    public function deleted(Model $model): void
    {
        app(ProcurementChainCache::class)->invalidateFor($model);
    }
}
