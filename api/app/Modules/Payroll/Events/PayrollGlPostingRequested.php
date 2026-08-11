<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Narrow, durable recovery request for one payroll-period → GL handoff. */
class PayrollGlPostingRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PayrollPeriod $period,
        public readonly string $reasonCode = 'payroll_gl_posting_requested',
    ) {}
}
