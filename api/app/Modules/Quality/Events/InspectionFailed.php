<?php

declare(strict_types=1);

namespace App\Modules\Quality\Events;

use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C1/C2. Fired by InspectionService::complete() when the
 * resulting status is `failed`. The existing NCR auto-open inside the
 * service handles the corrective-action side; this event drives the
 * chain-level cascade (e.g. RejectGRNOnQcFail).
 */
class InspectionFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Inspection $inspection) {}
}
