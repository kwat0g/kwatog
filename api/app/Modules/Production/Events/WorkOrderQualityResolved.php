<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Common\Events\ToleratesNewerModelState;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkOrderQualityResolved implements ToleratesNewerModelState
{
    use Dispatchable, SerializesModels;

    public function __construct(public WorkOrder $workOrder, public User $actor) {}
}
