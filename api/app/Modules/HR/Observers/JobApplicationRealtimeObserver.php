<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Services\RecruitmentRealtimeService;

final class JobApplicationRealtimeObserver
{
    public function created(JobApplication $application): void
    {
        $this->stage($application, 'created');
    }

    public function updated(JobApplication $application): void
    {
        $this->stage($application, $application->wasChanged('stage') ? 'status_changed' : 'updated');
    }

    private function stage(JobApplication $application, string $action): void
    {
        app(RecruitmentRealtimeService::class)->stageApplication($application, $action);
    }
}
