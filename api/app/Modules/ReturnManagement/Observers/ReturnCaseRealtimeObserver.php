<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Observers;

use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Services\ReturnCaseRealtimeService;

final class ReturnCaseRealtimeObserver
{
    public function created(ReturnCase $case): void
    {
        $this->stage($case, 'created');
    }

    public function updated(ReturnCase $case): void
    {
        $this->stage($case, 'updated');
    }

    public function deleted(ReturnCase $case): void
    {
        $this->stage($case, 'deleted');
    }

    public function restored(ReturnCase $case): void
    {
        $this->stage($case, 'updated');
    }

    private function stage(ReturnCase $case, string $action): void
    {
        app(ReturnCaseRealtimeService::class)->stage($case, $action);
    }
}
