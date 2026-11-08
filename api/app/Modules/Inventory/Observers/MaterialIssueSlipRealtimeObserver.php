<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Services\MaterialIssueSlipRealtimeService;

final class MaterialIssueSlipRealtimeObserver
{
    public function created(MaterialIssueSlip $slip): void
    {
        $this->stage($slip, 'created');
    }

    public function updated(MaterialIssueSlip $slip): void
    {
        $action = $slip->wasRecentlyCreated
            ? 'created'
            : ($slip->wasChanged('status') ? 'status_changed' : 'updated');

        $this->stage($slip, $action);
    }

    public function deleted(MaterialIssueSlip $slip): void
    {
        $this->stage($slip, 'deleted');
    }

    private function stage(MaterialIssueSlip $slip, string $action): void
    {
        app(MaterialIssueSlipRealtimeService::class)->stage($slip, $action);
    }
}
