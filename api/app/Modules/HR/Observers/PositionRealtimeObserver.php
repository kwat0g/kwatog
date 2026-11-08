<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\PositionRealtimeService;

/** Captures position writes from CRUD services and employee imports. */
final class PositionRealtimeObserver
{
    public function created(Position $position): void
    {
        app(PositionRealtimeService::class)->stage($position, 'created');
    }

    public function updated(Position $position): void
    {
        $action = $position->wasChanged('deleted_at') && $position->deleted_at === null
            ? 'restored'
            : 'updated';

        app(PositionRealtimeService::class)->stage($position, $action);
    }

    public function deleted(Position $position): void
    {
        app(PositionRealtimeService::class)->stage($position, 'deleted');
    }
}
