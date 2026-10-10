<?php

declare(strict_types=1);

namespace App\Modules\Quality\Events;

use App\Common\Events\ToleratesNewerModelState;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Approved conforming output becomes available without changing its failed verdict. */
class OutgoingQuantityReleased implements ToleratesNewerModelState
{
    use Dispatchable, SerializesModels;

    public function __construct(public Inspection $inspection) {}
}
