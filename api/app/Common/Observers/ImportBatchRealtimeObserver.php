<?php

declare(strict_types=1);

namespace App\Common\Observers;

use App\Common\Models\ImportBatch;
use App\Common\Services\ImportBatchRealtimeService;

/** Captures committed import history changes from all Eloquent write paths. */
final class ImportBatchRealtimeObserver
{
    public function created(ImportBatch $batch): void
    {
        app(ImportBatchRealtimeService::class)->stage($batch, 'created');
    }

    public function updated(ImportBatch $batch): void
    {
        if (! $batch->wasChanged([
            'entity_type', 'filename', 'status', 'total_rows', 'imported_rows',
            'created_by', 'rolled_back_at', 'rolled_back_by',
        ])) {
            return;
        }

        $action = $batch->wasRecentlyCreated
            ? 'created'
            : ($batch->wasChanged('status') ? 'status_changed' : 'updated');

        app(ImportBatchRealtimeService::class)->stage($batch, $action);
    }

    public function deleted(ImportBatch $batch): void
    {
        app(ImportBatchRealtimeService::class)->stage($batch, 'deleted');
    }
}
