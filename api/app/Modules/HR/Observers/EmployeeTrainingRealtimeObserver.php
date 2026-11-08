<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Services\EmployeeTrainingRealtimeService;

/** Captures training assignments, completions, expiry alerts, and cancellations. */
final class EmployeeTrainingRealtimeObserver
{
    public function created(EmployeeTraining $record): void
    {
        $this->stage($record, 'created');
    }

    public function updated(EmployeeTraining $record): void
    {
        $this->stage($record, $record->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(EmployeeTraining $record): void
    {
        $this->stage($record, 'deleted');
    }

    private function stage(EmployeeTraining $record, string $action): void
    {
        app(EmployeeTrainingRealtimeService::class)->stage($record, $action);
    }
}
