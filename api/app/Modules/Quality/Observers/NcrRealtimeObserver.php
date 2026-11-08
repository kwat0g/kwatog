<?php

declare(strict_types=1);

namespace App\Modules\Quality\Observers;

use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrRealtimeService;

final class NcrRealtimeObserver
{
    public function created(NonConformanceReport $ncr): void
    {
        app(NcrRealtimeService::class)->stage($ncr, 'created');
    }

    public function updated(NonConformanceReport $ncr): void
    {
        app(NcrRealtimeService::class)->stage($ncr, 'updated');
    }

    public function deleted(NonConformanceReport $ncr): void
    {
        app(NcrRealtimeService::class)->stage($ncr, 'deleted');
    }
}
