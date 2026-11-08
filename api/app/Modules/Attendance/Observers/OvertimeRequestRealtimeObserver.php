<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Observers;

use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeRequestRealtimeService;

/** Captures API, self-service, attendance-detection and job writes. */
final class OvertimeRequestRealtimeObserver
{
    public function created(OvertimeRequest $request): void
    {
        $this->stage($request, 'created');
    }

    public function updated(OvertimeRequest $request): void
    {
        $this->stage($request, $request->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(OvertimeRequest $request): void
    {
        $this->stage($request, 'deleted');
    }

    private function stage(OvertimeRequest $request, string $action): void
    {
        app(OvertimeRequestRealtimeService::class)->stage($request, $action);
    }
}
