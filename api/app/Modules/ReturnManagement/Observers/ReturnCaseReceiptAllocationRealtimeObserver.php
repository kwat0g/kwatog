<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Observers;

use App\Modules\ReturnManagement\Models\ReturnCaseReceiptAllocation;
use App\Modules\ReturnManagement\Services\ReturnCaseRealtimeService;

final class ReturnCaseReceiptAllocationRealtimeObserver
{
    public function created(ReturnCaseReceiptAllocation $allocation): void
    {
        $this->stage($allocation);
    }

    public function deleted(ReturnCaseReceiptAllocation $allocation): void
    {
        $this->stage($allocation);
    }

    private function stage(ReturnCaseReceiptAllocation $allocation): void
    {
        $line = $allocation->caseLine()->with('returnCase')->first();
        if ($line?->returnCase) {
            app(ReturnCaseRealtimeService::class)->stage($line->returnCase, 'updated');
        }
    }
}
