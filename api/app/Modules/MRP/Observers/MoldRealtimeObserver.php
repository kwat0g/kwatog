<?php

declare(strict_types=1);

namespace App\Modules\MRP\Observers;

use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\MoldRealtimeService;

final class MoldRealtimeObserver
{
    public function created(Mold $mold): void
    {
        $this->stage($mold, 'created');
    }

    public function updated(Mold $mold): void
    {
        if ($mold->wasChanged('deleted_at') && $mold->deleted_at === null) {
            // SoftDeletes::restore() also fires updated; emit one restore action.
            return;
        }

        $this->stage($mold, $mold->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(Mold $mold): void
    {
        $this->stage($mold, 'deleted');
    }

    public function restored(Mold $mold): void
    {
        $this->stage($mold, 'restored');
    }

    private function stage(Mold $mold, string $action): void
    {
        app(MoldRealtimeService::class)->stage($mold, $action);
    }
}
