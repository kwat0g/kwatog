<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C3. Fired by PayrollPeriodService::finalize(). The
 * existing service already handles GL posting + bank file generation;
 * this event opens the door for additional listeners (per-employee
 * payslip notifications, per-period summary email, etc.).
 */
class PayrollPeriodFinalized
{
    use Dispatchable, SerializesModels;

    public function __construct(public PayrollPeriod $period) {}
}
