<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\Skill;
use App\Modules\HR\Services\SkillRealtimeService;

/** Captures skill catalog changes from services, controllers, jobs, and imports. */
final class SkillRealtimeObserver
{
    public function created(Skill $skill): void
    {
        $this->stage($skill, 'created');
    }

    public function updated(Skill $skill): void
    {
        $action = $skill->wasChanged('deleted_at') && $skill->deleted_at === null
            ? 'restored'
            : 'updated';

        $this->stage($skill, $action);
    }

    public function deleted(Skill $skill): void
    {
        $this->stage($skill, 'deleted');
    }

    private function stage(Skill $skill, string $action): void
    {
        app(SkillRealtimeService::class)->stage($skill, $action);
    }
}
