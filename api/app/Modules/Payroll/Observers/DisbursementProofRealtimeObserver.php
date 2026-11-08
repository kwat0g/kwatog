<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Observers;

use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodRealtimeService;

/** Refreshes period viewers when embedded disbursement evidence changes. */
final class DisbursementProofRealtimeObserver
{
    public function created(DisbursementProof $proof): void
    {
        $this->stageParentRefresh($proof);
    }

    public function updated(DisbursementProof $proof): void
    {
        $this->stageParentRefresh($proof);
    }

    public function deleted(DisbursementProof $proof): void
    {
        $this->stageParentRefresh($proof);
    }

    private function stageParentRefresh(DisbursementProof $proof): void
    {
        $period = PayrollPeriod::query()->find($proof->payroll_period_id);
        if (! $period) {
            return;
        }

        app(PayrollPeriodRealtimeService::class)->stage(
            $period,
            'related_updated',
            $proof->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
        );
    }
}
