<?php

declare(strict_types=1);

namespace App\Modules\Quality\Observers;

use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Services\QualityRealtimeService;

final class ItemQualityPlanRealtimeObserver
{
    public function created(ItemQualityPlan $plan): void
    {
        $this->stage($plan, 'created');
    }

    public function updated(ItemQualityPlan $plan): void
    {
        $this->stage($plan, 'updated');
    }

    public function deleted(ItemQualityPlan $plan): void
    {
        $this->stage($plan, 'deleted');
    }

    private function stage(ItemQualityPlan $plan, string $action): void
    {
        app(QualityRealtimeService::class)->stage('quality_plan', $plan, $action);
    }
}
