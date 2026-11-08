<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Services\MaterialReviewRecordRealtimeService;

final class MaterialReviewRecordRealtimeObserver
{
    public function created(MaterialReviewRecord $record): void
    {
        $this->stage($record, 'created');
    }

    public function updated(MaterialReviewRecord $record): void
    {
        $action = $record->wasRecentlyCreated
            ? 'created'
            : ($record->wasChanged('status') ? 'status_changed' : 'updated');

        $this->stage($record, $action);
    }

    public function deleted(MaterialReviewRecord $record): void
    {
        $this->stage($record, 'deleted');
    }

    private function stage(MaterialReviewRecord $record, string $action): void
    {
        app(MaterialReviewRecordRealtimeService::class)->stage($record, $action);
    }
}
