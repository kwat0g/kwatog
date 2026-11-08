<?php

declare(strict_types=1);

namespace App\Modules\Quality\Observers;

use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrRealtimeService;

final class NcrActionRealtimeObserver
{
    public function created(NcrAction $action): void
    {
        $this->stage($action, 'updated');
    }

    public function updated(NcrAction $action): void
    {
        $this->stage($action, 'updated');
    }

    public function deleted(NcrAction $action): void
    {
        $this->stage($action, 'updated');
    }

    private function stage(NcrAction $action, string $change): void
    {
        $ncr = NonConformanceReport::query()->find($action->ncr_id);
        if ($ncr !== null) {
            app(NcrRealtimeService::class)->stage($ncr, $change);
        }
    }
}
