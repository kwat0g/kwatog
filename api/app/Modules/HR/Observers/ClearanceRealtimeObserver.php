<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Services\ClearanceRealtimeService;

final class ClearanceRealtimeObserver
{
    public function created(Clearance $clearance): void
    {
        app(ClearanceRealtimeService::class)->stage($clearance, 'created');
    }

    public function updated(Clearance $clearance): void
    {
        app(ClearanceRealtimeService::class)->stage($clearance, 'updated');
    }

    public function deleted(Clearance $clearance): void
    {
        app(ClearanceRealtimeService::class)->stage($clearance, 'deleted');
    }
}
