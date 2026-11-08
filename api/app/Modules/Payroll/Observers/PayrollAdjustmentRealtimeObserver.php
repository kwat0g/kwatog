<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Observers;

use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Services\PayrollAdjustmentRealtimeService;

/** Captures adjustment writes from HTTP, jobs, commands, and scheduled services. */
final class PayrollAdjustmentRealtimeObserver
{
    public function created(PayrollAdjustment $adjustment): void
    {
        app(PayrollAdjustmentRealtimeService::class)->stage($adjustment, 'created');
    }

    public function updated(PayrollAdjustment $adjustment): void
    {
        app(PayrollAdjustmentRealtimeService::class)->stage($adjustment, 'updated');
    }

    public function deleted(PayrollAdjustment $adjustment): void
    {
        app(PayrollAdjustmentRealtimeService::class)->stage($adjustment, 'deleted');
    }
}
