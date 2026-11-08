<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Services\EmployeeSkillRealtimeService;

/** Captures assignments, edits, revocations, and restores for every writer. */
final class EmployeeSkillRealtimeObserver
{
    public function created(EmployeeSkill $record): void
    {
        $this->stage($record, 'created');
    }

    public function updated(EmployeeSkill $record): void
    {
        $action = $record->wasChanged('deleted_at') && $record->deleted_at === null
            ? 'restored'
            : 'updated';

        $this->stage($record, $action);
    }

    public function deleted(EmployeeSkill $record): void
    {
        $this->stage($record, 'deleted');
    }

    private function stage(EmployeeSkill $record, string $action): void
    {
        app(EmployeeSkillRealtimeService::class)->stage($record, $action);
    }
}
