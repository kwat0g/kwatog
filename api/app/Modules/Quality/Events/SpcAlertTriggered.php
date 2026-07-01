<?php

declare(strict_types=1);

namespace App\Modules\Quality\Events;

use App\Modules\Quality\Models\SpcControlChart;
use App\Modules\Quality\Models\SpcDataPoint;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SpcAlertTriggered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly SpcControlChart $chart,
        public readonly SpcDataPoint $dataPoint,
        public readonly array $violations,
    ) {}
}
