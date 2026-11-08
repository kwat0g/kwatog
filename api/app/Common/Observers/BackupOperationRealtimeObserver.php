<?php

declare(strict_types=1);

namespace App\Common\Observers;

use App\Common\Models\BackupOperation;
use App\Common\Services\AdminBackupsRealtimeService;

/** Watches every Eloquent backup-ledger write, including worker transitions. */
final class BackupOperationRealtimeObserver
{
    private const VISIBLE_FIELDS = [
        'requested_by',
        'type',
        'status',
        'artifacts',
        'metadata',
        'error_message',
        'active_lock',
        'attempts',
        'started_at',
        'completed_at',
    ];

    public function created(BackupOperation $operation): void
    {
        app(AdminBackupsRealtimeService::class)->stageOperation($operation, 'created');
    }

    public function updated(BackupOperation $operation): void
    {
        if (array_intersect(array_keys($operation->getChanges()), self::VISIBLE_FIELDS) === []) {
            return;
        }

        $action = $operation->wasChanged('status') ? 'status_changed' : 'updated';
        app(AdminBackupsRealtimeService::class)->stageOperation($operation, $action);
    }

    public function deleted(BackupOperation $operation): void
    {
        app(AdminBackupsRealtimeService::class)->stageOperation($operation, 'deleted');
    }
}
