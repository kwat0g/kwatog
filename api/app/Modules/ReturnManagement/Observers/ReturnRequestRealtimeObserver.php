<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Observers;

use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestRealtimeService;

final class ReturnRequestRealtimeObserver
{
    public function created(ReturnRequest $request): void
    {
        $this->stage($request, 'created');
    }

    public function updated(ReturnRequest $request): void
    {
        $this->stage($request, 'updated');
    }

    public function deleted(ReturnRequest $request): void
    {
        $this->stage($request, 'deleted');
    }

    public function restored(ReturnRequest $request): void
    {
        $this->stage($request, 'updated');
    }

    private function stage(ReturnRequest $request, string $action): void
    {
        app(ReturnRequestRealtimeService::class)->stage($request, $action);
    }
}
