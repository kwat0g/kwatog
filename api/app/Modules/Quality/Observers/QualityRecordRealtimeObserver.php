<?php

declare(strict_types=1);

namespace App\Modules\Quality\Observers;

use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\PpapSubmission;
use App\Modules\Quality\Models\NcrTemplate;
use App\Modules\Quality\Services\QualityRealtimeService;

final class QualityRecordRealtimeObserver
{
    public function created(CalibrationRecord|InspectionSpec|NcrTemplate|PpapSubmission $record): void
    {
        $this->stage($record, 'created');
    }

    public function updated(CalibrationRecord|InspectionSpec|NcrTemplate|PpapSubmission $record): void
    {
        $this->stage($record, 'updated');
    }

    public function deleted(CalibrationRecord|InspectionSpec|NcrTemplate|PpapSubmission $record): void
    {
        $this->stage($record, 'deleted');
    }

    private function stage(CalibrationRecord|InspectionSpec|NcrTemplate|PpapSubmission $record, string $action): void
    {
        $entity = match (true) {
            $record instanceof CalibrationRecord => 'calibration',
            $record instanceof InspectionSpec => 'inspection_spec',
            $record instanceof NcrTemplate => 'ncr_template',
            default => 'ppap',
        };
        app(QualityRealtimeService::class)->stage($entity, $record, $action);
    }
}
