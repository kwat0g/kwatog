<?php

declare(strict_types=1);

namespace App\Modules\Loans\Observers;

use App\Common\Models\ApprovalRecord;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Services\EmployeeLoanRealtimeService;

/** Covers approval-chain changes that happen before the parent status moves. */
final class LoanApprovalRecordRealtimeObserver
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
        $this->stage($record, 'deleted');
    }

    private function stage(ApprovalRecord $record, string $action): void
    {
        $loan = $record->approvable;
        if (! $loan instanceof EmployeeLoan) {
            return;
        }

        app(EmployeeLoanRealtimeService::class)->stageApprovalChange(
            $loan,
            $record,
            $action,
        );
    }
}
