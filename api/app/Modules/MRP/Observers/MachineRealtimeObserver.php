<?php

declare(strict_types=1);

namespace App\Modules\MRP\Observers;

use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Services\MachineRealtimeService;

final class MachineRealtimeObserver
{
    public function created(Machine $machine): void
    {
        $this->stage($machine, 'created');
    }

    public function updated(Machine $machine): void
    {
        if ($machine->wasChanged('deleted_at') && $machine->deleted_at === null) {
            // SoftDeletes::restore() emits both updated and restored callbacks.
            // Let restored() publish the single lifecycle signal.
            return;
        }

        $this->stage($machine, $machine->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(Machine $machine): void
    {
        $this->stage($machine, 'deleted');
    }

    public function restored(Machine $machine): void
    {
        $this->stage($machine, 'restored');
    }

    private function stage(Machine $machine, string $action): void
    {
        app(MachineRealtimeService::class)->stage($machine, $action);
    }
}
