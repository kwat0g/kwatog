<?php

declare(strict_types=1);

namespace App\Modules\Production\Observers;

use App\Modules\Production\Models\LateProductionReport;
use App\Modules\Production\Services\LateProductionReportRealtimeService;

final class LateProductionReportRealtimeObserver
{
    public function created(LateProductionReport $report): void
    {
        $this->stage($report, 'created');
    }

    public function updated(LateProductionReport $report): void
    {
        $this->stage($report, $report->wasChanged('status') ? 'status_changed' : 'updated');
    }

    private function stage(LateProductionReport $report, string $action): void
    {
        app(LateProductionReportRealtimeService::class)->stage($report, $action);
    }
}
