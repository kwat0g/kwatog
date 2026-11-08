<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Observers;

use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodRealtimeService;

/** Captures period writes from HTTP, jobs, commands, and scheduled services. */
final class PayrollPeriodRealtimeObserver
{
    public function created(PayrollPeriod $period): void
    {
        app(PayrollPeriodRealtimeService::class)->stage($period, 'created');
    }

    public function updated(PayrollPeriod $period): void
    {
        app(PayrollPeriodRealtimeService::class)->stage($period, 'updated');
    }

    public function deleted(PayrollPeriod $period): void
    {
        app(PayrollPeriodRealtimeService::class)->stage($period, 'deleted');
    }
}
