<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Events;

use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Durable request for one claimed payroll period to enter the compute engine.
 *
 * The request is recorded in the same transaction as the Processing claim.
 * That keeps a queue outage from turning a valid claim into an untraceable
 * stranded period; the outbox dispatcher can publish this event later.
 */
class PayrollComputationRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PayrollPeriod $period,
        public readonly ?int $triggeredBy,
        public readonly string $requestId,
        public readonly ?string $claimToken = null,
    ) {}
}
