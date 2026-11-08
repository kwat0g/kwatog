<?php

declare(strict_types=1);

namespace App\Modules\Production\Observers;

use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Services\WorkOrderOperationRealtimeService;

final class WoOperationRealtimeObserver
{
    public function created(WoOperation $operation): void
    {
        $this->stage($operation, 'created');
    }

    public function updated(WoOperation $operation): void
    {
        $this->stage($operation, 'updated');
    }

    public function deleted(WoOperation $operation): void
    {
        $this->stage($operation, 'deleted');
    }

    private function stage(WoOperation $operation, string $action): void
    {
        app(WorkOrderOperationRealtimeService::class)->stage($operation, $action);
    }
}
