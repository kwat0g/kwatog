<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Observers;

use App\Modules\ReturnManagement\Models\ReturnCaseEvent;
use App\Modules\ReturnManagement\Services\ReturnCaseRealtimeService;

final class ReturnCaseEventRealtimeObserver
{
    public function created(ReturnCaseEvent $event): void
    {
        $case = $event->returnCase()->first();
        if ($case) {
            app(ReturnCaseRealtimeService::class)->stage($case, (string) $event->action, (bool) $event->is_public);
        }
    }
}
