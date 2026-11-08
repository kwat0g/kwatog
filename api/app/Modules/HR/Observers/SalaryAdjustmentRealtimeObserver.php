<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\SalaryAdjustment;
use App\Modules\HR\Services\SalaryAdjustmentRealtimeService;

/** Captures maker-checker and scheduled salary-adjustment writes. */
final class SalaryAdjustmentRealtimeObserver
{
    public function created(SalaryAdjustment $adjustment): void
    {
        app(SalaryAdjustmentRealtimeService::class)->stage($adjustment, 'created');
    }

    public function updated(SalaryAdjustment $adjustment): void
    {
        app(SalaryAdjustmentRealtimeService::class)->stage(
            $adjustment,
            $adjustment->wasChanged('status') ? 'status_changed' : 'updated',
        );
    }

    public function deleted(SalaryAdjustment $adjustment): void
    {
        app(SalaryAdjustmentRealtimeService::class)->stage($adjustment, 'deleted');
    }
}
