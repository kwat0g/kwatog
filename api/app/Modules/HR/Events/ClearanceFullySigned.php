<?php

declare(strict_types=1);

namespace App\Modules\HR\Events;

use App\Modules\HR\Models\Clearance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C3. Fired by SeparationService::signItem() when the
 * last outstanding item is signed off and the clearance flips to a
 * fully-signed state. Drives ComputeFinalPayAndDeactivate.
 */
class ClearanceFullySigned
{
    use Dispatchable, SerializesModels;

    public function __construct(public Clearance $clearance) {}
}
