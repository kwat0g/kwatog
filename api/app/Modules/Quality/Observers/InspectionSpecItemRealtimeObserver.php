<?php

declare(strict_types=1);

namespace App\Modules\Quality\Observers;

use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\QualityRealtimeService;

final class InspectionSpecItemRealtimeObserver
{
    public function created(InspectionSpecItem $item): void
    {
        $this->stage($item);
    }

    public function updated(InspectionSpecItem $item): void
    {
        $this->stage($item);
    }

    public function deleted(InspectionSpecItem $item): void
    {
        $this->stage($item);
    }

    private function stage(InspectionSpecItem $item): void
    {
        $spec = InspectionSpec::withTrashed()->find($item->inspection_spec_id);
        if ($spec !== null) {
            app(QualityRealtimeService::class)->stage('inspection_spec', $spec, 'updated');
        }
    }
}
