<?php

declare(strict_types=1);

namespace App\Modules\Assets\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Durable request for one asset-depreciation period. */
class MonthlyDepreciationRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly string $requestId,
    ) {}
}
