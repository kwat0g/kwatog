<?php

declare(strict_types=1);

namespace App\Common\Observers;

use App\Common\Models\ChainListenerRun;
use App\Common\Services\ChainListenerRunRealtimeService;

/** Captures recovery actions that use Eloquent, including replay and resolution. */
final class ChainListenerRunRealtimeObserver
{
    public function created(ChainListenerRun $run): void
    {
        app(ChainListenerRunRealtimeService::class)->stage($run, 'created');
    }

    public function updated(ChainListenerRun $run): void
    {
        $action = $run->wasChanged('resolution_status')
            ? 'resolved'
            : ($run->wasChanged('status') ? 'status_changed' : 'updated');

        app(ChainListenerRunRealtimeService::class)->stage($run, $action);
    }

    public function deleted(ChainListenerRun $run): void
    {
        app(ChainListenerRunRealtimeService::class)->stage($run, 'deleted');
    }
}
