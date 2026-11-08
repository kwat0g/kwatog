<?php

declare(strict_types=1);

namespace App\Modules\Loans\Observers;

use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Services\EmployeeLoanRealtimeService;

/** Captures API, payroll, final-pay, HR separation, and job-driven loan writes. */
final class EmployeeLoanRealtimeObserver
{
    public function created(EmployeeLoan $loan): void
    {
        $this->stage($loan, 'created');
    }

    public function updated(EmployeeLoan $loan): void
    {
        $this->stage($loan, $loan->wasChanged('status') ? 'status_changed' : 'updated');
    }

    public function deleted(EmployeeLoan $loan): void
    {
        $this->stage($loan, 'deleted');
    }

    private function stage(EmployeeLoan $loan, string $action): void
    {
        app(EmployeeLoanRealtimeService::class)->stage($loan, $action);
    }
}
