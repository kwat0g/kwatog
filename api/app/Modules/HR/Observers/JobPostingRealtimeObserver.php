<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Services\RecruitmentRealtimeService;

final class JobPostingRealtimeObserver
{
    public function created(JobPosting $posting): void
    {
        $this->stage($posting, 'created');
    }

    public function updated(JobPosting $posting): void
    {
        $action = $posting->wasChanged('deleted_at') && $posting->deleted_at === null
            ? 'restored'
            : ($posting->wasChanged('status') ? 'status_changed' : 'updated');

        $this->stage($posting, $action);
    }

    public function deleted(JobPosting $posting): void
    {
        $this->stage($posting, 'deleted');
    }

    private function stage(JobPosting $posting, string $action): void
    {
        app(RecruitmentRealtimeService::class)->stagePosting($posting, $action);
    }
}
