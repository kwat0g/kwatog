<?php

declare(strict_types=1);

namespace App\Modules\MRP\Observers;

use App\Modules\MRP\Models\MrpRun;
use App\Modules\MRP\Services\MrpRunRealtimeService;

final class MrpRunRealtimeObserver
{
    public function created(MrpRun $run): void
    {
        $this->stage($run, 'created');
    }

    public function updated(MrpRun $run): void
    {
        if (! $run->wasChanged(['status', 'summary', 'sales_orders_evaluated', 'shortages_found', 'prs_created', 'prs_updated', 'plans_generated', 'failed_sales_orders', 'duration_ms', 'error_code', 'recovery_action'])) {
            return;
        }

        $this->stage($run, $run->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(MrpRun $run): void
    {
        $this->stage($run, 'deleted');
    }

    private function stage(MrpRun $run, string $action): void
    {
        app(MrpRunRealtimeService::class)->stage($run, $action);
    }
}
