<?php

declare(strict_types=1);

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Services\PriceRequestRealtimeService;

final class PriceRequestRealtimeObserver
{
    public function created(PriceRequest $request): void
    {
        $this->stage($request, 'created');
    }

    public function updated(PriceRequest $request): void
    {
        $this->stage($request, 'updated');
    }

    public function deleted(PriceRequest $request): void
    {
        $this->stage($request, 'deleted');
    }

    private function stage(PriceRequest $request, string $action): void
    {
        app(PriceRequestRealtimeService::class)->stage($request, $action);
    }
}
