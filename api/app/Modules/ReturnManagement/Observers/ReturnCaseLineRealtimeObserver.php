<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Observers;

use App\Modules\ReturnManagement\Models\ReturnCaseLine;
use App\Modules\ReturnManagement\Services\ReturnCaseRealtimeService;

final class ReturnCaseLineRealtimeObserver
{
    public function created(ReturnCaseLine $line): void
    {
        $this->stage($line);
    }

    public function updated(ReturnCaseLine $line): void
    {
        $this->stage($line);
    }

    public function deleted(ReturnCaseLine $line): void
    {
        $this->stage($line);
    }

    private function stage(ReturnCaseLine $line): void
    {
        $case = $line->returnCase()->first();
        if ($case) {
            app(ReturnCaseRealtimeService::class)->stage($case, 'updated');
        }
    }
}
