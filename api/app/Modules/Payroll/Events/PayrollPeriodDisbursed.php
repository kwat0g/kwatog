<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * P3.4 — Fired by PayrollPeriodService::markDisbursed() once the period
 * transitions to the Disbursed status. Kept separate from
 * PayrollPeriodFinalized so listeners can distinguish "payslip ready"
 * (finalize) from "salary credited" (disbursed) without relying on the
 * period's status inside the handler.
 */
class PayrollPeriodDisbursed
{
    use Dispatchable, SerializesModels;

    public function __construct(public PayrollPeriod $period) {}
}
