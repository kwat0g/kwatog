<?php

declare(strict_types=1);

namespace App\Modules\Quality\Observers;

use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionResultAuthor;

/** Refreshes assigned operators when a QC result-author record changes release readiness. */
final class InspectionResultAuthorRealtimeObserver
{
    public function created(InspectionResultAuthor $author): void
    {
        $this->stage($author);
    }

    public function deleted(InspectionResultAuthor $author): void
    {
        $this->stage($author);
    }

    private function stage(InspectionResultAuthor $author): void
    {
        $inspection = Inspection::query()->find($author->inspection_id);
        if ($inspection) {
            app(AssignedWorkOrderRefreshService::class)->stageForInspection($inspection);
        }
    }
}
