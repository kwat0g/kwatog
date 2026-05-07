<?php

declare(strict_types=1);

namespace App\Modules\Quality\Events;

use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C1/C2. Fired by InspectionService::complete() when the
 * resulting status is `passed`. Listeners filter by `stage` to scope to
 * incoming (P2P → AcceptGRNAndDraftBill) or outgoing (O2C → CreateDeliveryDraftOnQcPass).
 */
class InspectionPassed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Inspection $inspection) {}
}
