<?php

declare(strict_types=1);

namespace App\Modules\Loans\Observers;

use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Loans\Services\EmployeeLoanRealtimeService;

/** Bridges payment-ledger mutations to the parent loan refresh signal. */
final class LoanPaymentRealtimeObserver
{
    public function created(LoanPayment $payment): void
    {
        $this->stage($payment);
    }

    public function updated(LoanPayment $payment): void
    {
        $this->stage($payment);
    }

    public function deleted(LoanPayment $payment): void
    {
        $this->stage($payment);
    }

    private function stage(LoanPayment $payment): void
    {
        $loan = $payment->loan;
        if ($loan !== null) {
            app(EmployeeLoanRealtimeService::class)->stage($loan, 'updated');
        }
    }
}
