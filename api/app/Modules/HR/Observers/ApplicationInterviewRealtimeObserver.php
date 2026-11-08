<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Services\RecruitmentRealtimeService;

final class ApplicationInterviewRealtimeObserver
{
    public function created(ApplicationInterview $interview): void
    {
        $this->stage($interview, 'updated');
    }

    public function updated(ApplicationInterview $interview): void
    {
        $this->stage($interview, 'updated');
    }

    private function stage(ApplicationInterview $interview, string $action): void
    {
        app(RecruitmentRealtimeService::class)->stageInterview($interview, $action);
    }
}
