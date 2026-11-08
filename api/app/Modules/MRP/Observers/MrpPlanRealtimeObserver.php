<?php

declare(strict_types=1);

namespace App\Modules\MRP\Observers;

use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Services\MrpPlanRealtimeService;

final class MrpPlanRealtimeObserver
{
    public function created(MrpPlan $plan): void
    {
        $this->stage($plan, 'created');
    }

    public function updated(MrpPlan $plan): void
    {
        $this->stage($plan, $plan->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(MrpPlan $plan): void
    {
        $this->stage($plan, 'deleted');
    }

    private function stage(MrpPlan $plan, string $action): void
    {
        app(MrpPlanRealtimeService::class)->stage($plan, $action);
    }
}
