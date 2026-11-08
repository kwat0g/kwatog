<?php

declare(strict_types=1);

namespace App\Common\Observers;

use App\Common\Models\ScheduledExport;
use App\Common\Services\ScheduledExportRealtimeService;

/** Captures controller/import Eloquent lifecycle writes. */
final class ScheduledExportRealtimeObserver
{
    public function created(ScheduledExport $export): void
    {
        app(ScheduledExportRealtimeService::class)->stage($export, 'created');
    }

    public function updated(ScheduledExport $export): void
    {
        $action = $export->wasChanged('is_active') || $export->wasChanged('deleted_at')
            ? 'status_changed'
            : 'updated';
        app(ScheduledExportRealtimeService::class)->stage($export, $action);
    }

    public function deleted(ScheduledExport $export): void
    {
        app(ScheduledExportRealtimeService::class)->stage($export, 'deleted');
    }
}
