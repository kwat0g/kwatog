<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\ApplicationNote;
use App\Modules\HR\Services\RecruitmentRealtimeService;

final class ApplicationNoteRealtimeObserver
{
    public function created(ApplicationNote $note): void
    {
        app(RecruitmentRealtimeService::class)->stageNote($note, 'updated');
    }
}
