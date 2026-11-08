<?php

declare(strict_types=1);

namespace App\Modules\Quality\Observers;

use App\Modules\Quality\Models\PpapElement;
use App\Modules\Quality\Models\PpapSubmission;
use App\Modules\Quality\Services\QualityRealtimeService;

final class PpapElementRealtimeObserver
{
    public function created(PpapElement $element): void
    {
        $this->stage($element);
    }

    public function updated(PpapElement $element): void
    {
        $this->stage($element);
    }

    public function deleted(PpapElement $element): void
    {
        $this->stage($element);
    }

    private function stage(PpapElement $element): void
    {
        $submission = PpapSubmission::query()->find($element->ppap_submission_id);
        if ($submission !== null) {
            app(QualityRealtimeService::class)->stage('ppap', $submission, 'updated');
        }
    }
}
