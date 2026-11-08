<?php

declare(strict_types=1);

namespace App\Modules\MRP\Observers;

use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Services\BomRealtimeService;

final class BomRealtimeObserver
{
    public function created(Bom $bom): void
    {
        $this->stage($bom, 'created');
    }

    public function updated(Bom $bom): void
    {
        if ($bom->wasChanged('deleted_at') && $bom->deleted_at === null) {
            // SoftDeletes::restore() also emits updated; restored() owns that signal.
            return;
        }

        $this->stage($bom, $bom->wasChanged('is_active') ? 'status_changed' : 'updated');
    }

    public function deleted(Bom $bom): void
    {
        $this->stage($bom, 'deleted');
    }

    public function restored(Bom $bom): void
    {
        $this->stage($bom, 'restored');
    }

    private function stage(Bom $bom, string $action): void
    {
        app(BomRealtimeService::class)->stage($bom, $action);
    }
}
