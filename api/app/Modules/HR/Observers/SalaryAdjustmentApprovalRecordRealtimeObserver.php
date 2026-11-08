<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Common\Models\ApprovalRecord;
use App\Modules\HR\Models\SalaryAdjustment;
use App\Modules\HR\Services\SalaryAdjustmentRealtimeService;

/** Covers approval-chain changes that happen before a salary status moves. */
final class SalaryAdjustmentApprovalRecordRealtimeObserver
{
    public function created(ApprovalRecord $record): void
    {
        $this->stage($record, 'created');
    }

    public function updated(ApprovalRecord $record): void
    {
        $this->stage($record, $record->wasChanged('action') ? 'status_changed' : 'updated');
    }

    public function deleted(ApprovalRecord $record): void
    {
        $this->stage($record, 'updated');
    }

    private function stage(ApprovalRecord $record, string $action): void
    {
        $adjustment = $record->approvable;
        if (! $adjustment instanceof SalaryAdjustment) {
            return;
        }

        app(SalaryAdjustmentRealtimeService::class)->stageApprovalChange($adjustment, $action);
    }
}
