<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Observers;

use App\Modules\Payroll\Models\DeMinimisBenefit;
use App\Modules\Payroll\Services\DeMinimisRealtimeService;

/** Captures ledger writes from HTTP, payroll jobs and service code. */
final class DeMinimisBenefitRealtimeObserver
{
    public function created(DeMinimisBenefit $benefit): void
    {
        app(DeMinimisRealtimeService::class)->stage($benefit, 'created');
    }

    public function updated(DeMinimisBenefit $benefit): void
    {
        $action = $benefit->wasChanged('deleted_at') && $benefit->deleted_at === null
            ? 'restored'
            : 'updated';

        app(DeMinimisRealtimeService::class)->stage($benefit, $action);
    }

    public function deleted(DeMinimisBenefit $benefit): void
    {
        app(DeMinimisRealtimeService::class)->stage($benefit, 'deleted');
    }
}
